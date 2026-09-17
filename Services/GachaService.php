<?php

namespace Plugin\RhythmGacha\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Plugin\RhythmGacha\Models\RyUser;
use Plugin\RhythmGacha\Models\RyItem;
use Plugin\RhythmGacha\Models\RyBackpack;
use Plugin\RhythmGacha\Models\RyGachaPool;

class GachaService
{
    /**
     * 执行抽卡
     * @param int $userId 用户ID
     * @param int $pullCount 抽数 (1 或 10)
     * @param string $poolCode 卡池标识符 (如 up_void 或 standard_base)
     */
    public function pull(int $userId, int $pullCount, string $poolCode): array
    {
        $singlePrice = 250;
        $totalCost = $singlePrice * $pullCount;

        return DB::transaction(function () use ($userId, $pullCount, $poolCode, $totalCost) {
            // 1. 锁用户资产行，杜绝并发
            $ryUser = RyUser::where('user_id', $userId)->lockForUpdate()->first();
            if (!$ryUser) throw new Exception("用户不存在");
            if ($ryUser->gems < $totalCost) throw new Exception("宝石余额不足，快去打歌赚取吧！");

            // 2. 获取并校验目标卡池
            $pool = RyGachaPool::where('code', $poolCode)->where('is_active', true)->first();
            if (!$pool) throw new Exception("指定的卡池不存在或已关闭！");

            $isUpPool = ($pool->type === 'up');

            // 扣除宝石
            $ryUser->gems -= $totalCost;
            $results = [];

            // 3. 执行单抽/十连循环
            for ($i = 0; $i < $pullCount; $i++) {
                // 推进对应卡池的 5 星与 4 星保底计数
                if ($isUpPool) {
                    $ryUser->pity_up++;
                    $ryUser->pity_4_up++;
                    $pity5 = $ryUser->pity_up;
                    $pity4 = $ryUser->pity_4_up;
                } else {
                    $ryUser->pity_standard++;
                    $ryUser->pity_4_standard++;
                    $pity5 = $ryUser->pity_standard;
                    $pity4 = $ryUser->pity_4_standard;
                }

                // 核心判定星级 (5星90抽硬保底，4星10抽硬保底)
                $star = $this->determineStarLevel($pity5, $pity4);

                // 根据出货重置对应计数器 (互不干扰)
                if ($star === 5) {
                    if ($isUpPool) $ryUser->pity_up = 0;
                    else $ryUser->pity_standard = 0;
                } elseif ($star === 4) {
                    if ($isUpPool) $ryUser->pity_4_up = 0;
                    else $ryUser->pity_4_standard = 0;
                }

                // 抽取具体道具 (包含大小保底与歪卡逻辑)
                $item = $this->pickItemFromPool($star, $pool, $ryUser);
                if (!$item) throw new Exception("道具池中缺少 {$star}★ 物资，请检查配置！");

                // 发放入背包
                RyBackpack::create([
                    'user_id' => $userId,
                    'item_id' => $item->id,
                    'amount'  => 1,
                    'source'  => $pool->code
                ]);

                $results[] = [
                    'item_name'  => $item->name,
                    'star'       => $item->star_level,
                    'type'       => $item->type,
                    'value_min'  => $item->value_min,
                    'value_max'  => $item->value_max
                ];
            }

            $ryUser->save();
            return $results;
        });
    }

    /**
     * 判定星级：5星 90 抽硬保底，4星 10 抽硬保底
     */
    private function determineStarLevel(int $pity5, int $pity4): int
    {
        // 90 抽必出 5★
        if ($pity5 >= 90) {
            return 5;
        }

        // 基础概率：5★ 0.6% (万分之60)，4★ 5.1% (万分之510)
        $rand = mt_rand(1, 10000);

        if ($rand <= 60) {
            return 5;
        }

        // 10 抽必出 4★ (逢10必出，且不影响自然出4星)
        if ($pity4 >= 10 || $rand <= (60 + 510)) {
            return 4;
        }

        // 其余全为 3★ 基础保底
        return 3;
    }

    /**
     * 卡池出装核心逻辑 (含二次必中保底与多UP支持)
     */
    private function pickItemFromPool(int $star, RyGachaPool $pool, RyUser &$ryUser): ?RyItem
    {
        // ================= 5 星出货逻辑 =================
        if ($star === 5) {
            if ($pool->type === 'up') {
                $up5Ids = $pool->up_5_star_ids ?? [];
                
                // 二次保底机制：上次歪了则 100% 必出 UP！未歪则 50% 概率出 UP
                $isWinUp = $ryUser->is_next_up_guaranteed || (mt_rand(1, 100) <= 50);

                if (!empty($up5Ids) && $isWinUp) {
                    // 命中当期 UP 5★ (支持多UP，随机选一)
                    $ryUser->is_next_up_guaranteed = false; // 消耗大保底
                    $targetId = $up5Ids[array_rand($up5Ids)];
                    return RyItem::find($targetId);
                } else {
                    // 歪了！出非UP的常驻 5★，并激活下次【大保底必中 UP】！
                    $ryUser->is_next_up_guaranteed = true;
                    
                    // 从常驻5星库中挑一个（排除掉当期已配置的UP道具）
                    $query = RyItem::where('star_level', 5)->where('is_in_standard_pool', true);
                    if (!empty($up5Ids)) {
                        $query->whereNotIn('id', $up5Ids);
                    }
                    $item = $query->inRandomOrder()->first();
                    return $item ?? RyItem::where('star_level', 5)->inRandomOrder()->first();
                }
            } else {
                // 常驻池：完全随机出常驻 5★
                return RyItem::where('star_level', 5)->where('is_in_standard_pool', true)->inRandomOrder()->first();
            }
        }

        // ================= 4 星出货逻辑 =================
        if ($star === 4) {
            if ($pool->type === 'up') {
                $up4Ids = $pool->up_4_star_ids ?? [];
                // 4星没有二次大保底，但如果在UP池有 50% 概率命中 4星UP
                if (!empty($up4Ids) && mt_rand(1, 100) <= 50) {
                    $targetId = $up4Ids[array_rand($up4Ids)];
                    return RyItem::find($targetId);
                }
            }
            // 否则出公共常驻 4★
            return RyItem::where('star_level', 4)->where('is_in_standard_pool', true)->inRandomOrder()->first();
        }

        // ================= 3 星公共物资 =================
        // 所有池子完全共享基础常驻 3★ 盲盒
        return RyItem::where('star_level', 3)->where('is_in_standard_pool', true)->inRandomOrder()->first();
    }
}