<?php

namespace Plugin\RhythmGacha\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Plugin\RhythmGacha\Models\RyUser;
use Plugin\RhythmGacha\Models\RyItem;
use Plugin\RhythmGacha\Models\RyBackpack;

class MonthlyPassService
{
    /**
     * 核心：发放月票 (即时生效 + 背包统一入库 + 天数无限叠加)
     * @param int $userId Xboard 用户ID
     * @param int $days 延长天数 (默认 30 天)
     * @param string $source 来源 (如: PLAN_3, EPAY_BUY, BALANCE_BUY, GACHA)
     */
    public function grantPass(int $userId, int $days = 30, string $source = 'MANUAL'): array
    {
        return DB::transaction(function () use ($userId, $days, $source) {
            $ryUser = RyUser::where('user_id', $userId)->lockForUpdate()->first();
            if (!$ryUser) {
                $ryUser = RyUser::create(['user_id' => $userId]);
            }

            // 1. 查找字典里的月票道具 ID
            $passItem = RyItem::where('type', 'monthly_pass')->first();
            $itemId = $passItem ? $passItem->id : 0;

            // 2. 写入背包记录 (保持资产全网统一，但标记为已自动使用)
            if ($itemId > 0) {
                RyBackpack::create([
                    'user_id' => $userId,
                    'item_id' => $itemId,
                    'amount'  => 1,
                    'is_used' => true,
                    'used_at' => now(),
                    'source'  => $source
                ]);
            }

            // 3. 计算并顺延月票到期时间 (如果当前还在有效期，在现有基础上往后加；已过期则从当前时间加)
            $now = time();
            $currentExpire = ($ryUser->monthly_pass_expire && strtotime($ryUser->monthly_pass_expire) > $now)
                ? strtotime($ryUser->monthly_pass_expire)
                : $now;

            $newExpireTimestamp = $currentExpire + ($days * 86400);
            $newExpireDate = date('Y-m-d H:i:s', $newExpireTimestamp);

            $ryUser->monthly_pass_expire = $newExpireDate;
            $ryUser->save();

            Log::info("RhythmGacha: 月票成功发放! 用户[{$userId}], 延期[{$days}天], 新到期时间: [{$newExpireDate}], 来源: [{$source}]");

            return [
                'days_added' => $days,
                'expire_at'  => $newExpireDate,
                'source'     => $source
            ];
        });
    }
}