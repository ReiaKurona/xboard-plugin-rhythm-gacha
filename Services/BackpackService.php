<?php

namespace Plugin\RhythmGacha\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Plugin\RhythmGacha\Models\RyBackpack;
use Plugin\RhythmGacha\Models\RyUser;
use App\Models\User;

class BackpackService
{
    public function useItem(int $userId, int $backpackId): array
    {
        return DB::transaction(function () use ($userId, $backpackId) {
            // 1. 悲观锁锁定当前背包道具
            $backpack = RyBackpack::where('id', $backpackId)
                        ->where('user_id', $userId)
                        ->lockForUpdate()
                        ->first();

            if (!$backpack) throw new Exception("物品不存在或不属于您");
            if ($backpack->is_used) throw new Exception("该物品已核销，请勿重复使用");

            $item = $backpack->item;
            if (!$item) throw new Exception("道具字典不存在");

            // 2. 悲观锁直接锁定 Xboard 原生用户表 (原子操作，杜绝并发与异步污染)
            $user = User::where('id', $userId)->lockForUpdate()->first();
            if (!$user) throw new Exception("Xboard 关联用户不存在");

            // 记录核销前的绝对物理字节底仓
            $beforeBytes = (int)$user->transfer_enable;
            $beforeGB = round($beforeBytes / 1073741824, 6);

            // 3. 标记核销
            $backpack->is_used = true;
            $backpack->used_at = now();
            $backpack->save();

            // 4. 严格直接从数据库提取 MB 区间
            $minMb = (int)$item->value_min;
            $maxMb = (int)$item->value_max;

            if ($minMb === $maxMb) {
                $calcType = "固定数值提取";
                $rewardMb = $minMb;
            } else {
                $calcType = "区间动态随机 [mt_rand({$minMb}, {$maxMb})]";
                $rewardMb = mt_rand(min($minMb, $maxMb), max($minMb, $maxMb));
            }

            // 5. 核心物理换算 (1 MB = 1048576 字节)
            $bytesToAdd = $rewardMb * 1048576;
            $gbEquivalent = round($rewardMb / 1024, 6);

            // 6. 核心动作：砍掉中间监听器，直接在这里物理入账！
            switch ($item->type) {
                case 'traffic':
                    $user->transfer_enable += $bytesToAdd;
                    $user->save();
                    break;

                case 'stamina_card':
                    $ryUser = RyUser::where('user_id', $userId)->first();
                    if ($ryUser) {
                        app(StaminaService::class)->addStamina($ryUser, $rewardMb);
                    }
                    break;

                case 'monthly_pass':
                    $ryUser = RyUser::where('user_id', $userId)->first();
                    if ($ryUser) {
                        $currentExpire = $ryUser->monthly_pass_expire ? strtotime($ryUser->monthly_pass_expire) : time();
                        $newExpire = max($currentExpire, time()) + ($rewardMb * 86400);
                        $ryUser->monthly_pass_expire = date('Y-m-d H:i:s', $newExpire);
                        $ryUser->save();
                    }
                    break;
            }

            // 7. 取出绝对真实的写入后数值
            $afterBytes = (int)$user->transfer_enable;
            $afterGB = round($afterBytes / 1073741824, 6);

            // 8. 组装 100% 真实的物理审计日志
            $processLogs = [
                "📦 [核销鉴权] 用户UID: {$userId} | 背包卡券ID: {$backpackId}",
                "📖 [参数读取] 物资: 【{$item->name}】({$item->star_level}★) | 数据库设定区间: {$minMb} ~ {$maxMb} MB",
                "🎲 [运算逻辑] {$calcType} => 命中结果: {$rewardMb} MB",
                "⚙️ [底层换算] {$rewardMb} MB × 1,048,576 = " . number_format($bytesToAdd) . " 字节 (换算为 {$gbEquivalent} GB)",
                "💾 [配额入账] Xboard基准额度: {$beforeGB} GB ➔ {$afterGB} GB (物理精确增加 +" . number_format($bytesToAdd) . " Bytes)"
            ];

            Log::info("RhythmGacha原子核销完成: " . implode(" | ", $processLogs));

            return [
                'item_name'     => $item->name,
                'reward_type'   => $item->type,
                'reward_amount' => $rewardMb,
                'calc_details'  => [
                    'min_mb'        => $minMb,
                    'max_mb'        => $maxMb,
                    'reward_mb'     => $rewardMb,
                    'bytes_added'   => $bytesToAdd,
                    'gb_equivalent' => $gbEquivalent,
                    'before_gb'     => $beforeGB,
                    'after_gb'      => $afterGB,
                    'process_logs'  => $processLogs
                ]
            ];
        });
    }
}