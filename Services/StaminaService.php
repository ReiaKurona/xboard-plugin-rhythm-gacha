<?php

namespace Plugin\RhythmGacha\Services;

use Exception;
use Illuminate\Support\Facades\Log;

class StaminaService
{
    /**
     * 惰性计算：获取实时体力（每次前端查询、打歌前调用）
     */
    public function getRealtimeStamina($ryUser, $pluginConfig): array
    {
        // 1. 管理员特权：无限体力，且免冷却
        if ($ryUser->is_admin) {
            return [
                'stamina' => 9999,
                'max_stamina' => 9999,
                'seconds_to_next' => 0,
                'is_vip' => true
            ];
        }

        // 2. 读取上限配置
        $baseMax = (int)($pluginConfig['stamina_base_max'] ?? 10);
        $vipMax = (int)($pluginConfig['stamina_vip_max'] ?? 20);
        
        // 验证月票是否过期
        $isVip = $ryUser->monthly_pass_expire && strtotime($ryUser->monthly_pass_expire) > time();
        $maxStamina = $isVip ? $vipMax : $baseMax;

        $currentStamina = $ryUser->stamina;
        // 新用户首次加载，自动初始化为满体力
        if ($currentStamina === null) {
            $currentStamina = $maxStamina;
            $ryUser->stamina = $maxStamina;
            $ryUser->last_stamina_update = date('Y-m-d H:i:s', time());
            $ryUser->save();
        }
        // 如果从来没记录过时间，默认用现在
        $lastUpdate = strtotime($ryUser->last_stamina_update ?: now());
        $now = time();
        $recoverSecs = (int)($pluginConfig['stamina_recover_mins'] ?? 45) * 60;

        // 3. 滑动窗口结算逻辑
        if ($currentStamina < $maxStamina) {
            $elapsed = $now - $lastUpdate;
            if ($elapsed >= $recoverSecs) {
                // 计算流失的时间里恢复了几点
                $recovered = (int)floor($elapsed / $recoverSecs);
                $newStamina = min($maxStamina, $currentStamina + $recovered);

                // 核心：平移时间锚点（把多余的零头秒数保留下来）
                $newLastUpdate = $lastUpdate + ($recovered * $recoverSecs);

                // 写入数据库保存最新快照
                $ryUser->stamina = $newStamina;
                $ryUser->last_stamina_update = date('Y-m-d H:i:s', $newLastUpdate);
                $ryUser->save();

                $currentStamina = $newStamina;
                $lastUpdate = $newLastUpdate;
            }
        } elseif ($currentStamina > $maxStamina) {
            // 4. 溢出逻辑（比如满体力时吃了体力增加卡）
            // 溢出期间停止自然恢复。一旦消耗，必须从此刻重新开始计时。
            $ryUser->last_stamina_update = date('Y-m-d H:i:s', $now);
            $ryUser->save();
            $lastUpdate = $now;
        }

        // 5. 计算前端显示的倒计时
        $elapsed = $now - $lastUpdate;
        $secondsToNext = ($currentStamina >= $maxStamina) ? 0 : ($recoverSecs - ($elapsed % $recoverSecs));

        return [
            'stamina' => $currentStamina,
            'max_stamina' => $maxStamina,
            'seconds_to_next' => $secondsToNext,
            'is_vip' => $isVip
        ];
    }

    /**
     * 扣除体力（打歌前调用）
     */
    public function consumeStamina($ryUser, $pluginConfig, int $amount = 1): bool
    {
        if ($ryUser->is_admin) return true;

        // 先做一次实时体力结算，防止读到旧数据
        $realtime = $this->getRealtimeStamina($ryUser, $pluginConfig);

        if ($realtime['stamina'] < $amount) {
            throw new Exception("体力不足！请等待恢复或使用能量卡。");
        }

        // 如果原先是满体力，被扣了一点，需要重新激活计时器锚点为当前时间
        if ($realtime['stamina'] >= $realtime['max_stamina']) {
            $ryUser->last_stamina_update = date('Y-m-d H:i:s', time());
        }

        $ryUser->stamina -= $amount;
        $ryUser->save();

        return true;
    }

    /**
     * 吃药/氪金增加体力（允许溢出上限）
     */
    public function addStamina($ryUser, int $amount): bool
    {
        $ryUser->stamina += $amount;
        $ryUser->save();
        // 注：这里不改 last_stamina_update，交由 getRealtimeStamina 下次访问时处理溢出挂起逻辑
        return true;
    }
}