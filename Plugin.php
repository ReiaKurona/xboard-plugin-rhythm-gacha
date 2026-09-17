<?php

namespace Plugin\RhythmGacha;

use App\Services\Plugin\AbstractPlugin;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Plugin\RhythmGacha\Events\ItemRedeemed;
use Plugin\RhythmGacha\Listeners\DeliverXboardItem;
use Plugin\RhythmGacha\Models\RyUser;
use Plugin\RhythmGacha\Models\RyOsuScore;
use Plugin\RhythmGacha\Models\RyMaimaiScore;
use Plugin\RhythmGacha\Services\Game\OsuDriver;
use Plugin\RhythmGacha\Services\Game\MaimaiDriver;
use Plugin\RhythmGacha\Services\StaminaService;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->filter('guest_comm_config', function ($config) {
            $config['rhythm_gacha_enable'] = true;
            $config['rhythm_gacha_stamina_max'] = (int)$this->getConfig('stamina_base_max', 10);
            return $config;
        });

        Event::listen(ItemRedeemed::class, DeliverXboardItem::class);

        // ================= 核心新增：真·非阻塞后台全自动注册 Webhook =================
        try {
            $botEnabled = (bool)$this->getConfig('tg_bot_enable', false);
            $botToken = trim((string)$this->getConfig('tg_bot_token', ''));
            $webhookBase = trim((string)$this->getConfig('tg_webhook_base_url', ''));

            if ($botEnabled && !empty($botToken) && !empty($webhookBase)) {
                $hashKey = 'rhythm_gacha_tg_auto_hook_hash';
                $currentHash = md5($botToken . $webhookBase);

                // 检测到配置变动或首次启用
                if (\Illuminate\Support\Facades\Cache::get($hashKey) !== $currentHash) {
                    // 核心技术：使用 app()->terminating()！
                    // 作用：在当前 HTTP 请求全部处理完毕、响应已返回给浏览器之后，在后台静默调用！
                    // 绝对零延迟、绝对不卡死 Swoole、不阻塞任何正常请求！
                    app()->terminating(function () use ($hashKey, $currentHash) {
                        try {
                            $botService = app(\Plugin\RhythmGacha\Services\TelegramBotService::class);
                            $botService->initCustomConfig($this->getConfig());
                            $res = $botService->registerWebhook();
                            
                            if ($res['ok'] ?? false) {
                                \Illuminate\Support\Facades\Cache::put($hashKey, $currentHash, 86400 * 30);
                                \Illuminate\Support\Facades\Log::info("RhythmGacha: 后台静默全自动注册独立 Webhook 成功！");
                            }
                        } catch (\Throwable $e) {
                            \Illuminate\Support\Facades\Log::warning("RhythmGacha: 自动注册失败: " . $e->getMessage());
                        }
                    });
                }
            }
        } catch (\Throwable $e) {}

        // 监听订单支付自动送月票
        $this->listen('payment.notify.verified', function (array $orderData) {
            try {
                $configStr = (string)$this->getConfig('bundled_pass_plan_ids', '3,4,5,24,25');
                $allowedPlanIds = array_filter(array_map('trim', explode(',', $configStr)));

                $tradeNo = $orderData['trade_no'] ?? null;
                $order = $tradeNo ? \App\Models\Order::where('trade_no', $tradeNo)->first() : null;

                if ($order && in_array((string)$order->plan_id, $allowedPlanIds)) {
                    $days = (int)$this->getConfig('monthly_pass_days', 30);
                    app(\Plugin\RhythmGacha\Services\MonthlyPassService::class)->grantPass(
                        (int)$order->user_id, 
                        $days, 
                        "PLAN_{$order->plan_id}"
                    );
                    Log::info("RhythmGacha: 套餐 [{$order->plan_id}] 成功赠送 30 天月票！");
                }
            } catch (\Exception $e) {
                Log::error("RhythmGacha 自动月票异常: " . $e->getMessage());
            }
        });

        
    }

    /**
     * 动态事件驱动与定时调度器
     */
    public function schedule(Schedule $schedule): void
    {
        // 每分钟唤起一次轻量检测，内部严格遵循后台配置的秒数进行门禁放行
        $schedule->call(function () {
            // 1. 读取后台配置的秒数，默认 300 秒 (5分钟)
            $intervalSeconds = (int)$this->getConfig('sync_interval_seconds', 300);
            if ($intervalSeconds <= 0) $intervalSeconds = 300;

            $cacheKey = 'rhythm_gacha_last_auto_sync_timestamp';
            $lastRun = (int)Cache::get($cacheKey, 0);
            $now = time();

            // 动态滑动窗口门禁：如果经过的时间小于设定的秒数，直接毫秒级跳过，不耗算力
            if (($now - $lastRun) < $intervalSeconds) {
                return;
            }

            // 命中时间，更新时间戳锁
            Cache::put($cacheKey, $now, 86400);
            Log::info("RhythmGacha: 触发全自动后台巡检 (设定频率: {$intervalSeconds} 秒/次)...");

            // 2. 执行全员双轨自动巡检
            $this->runDynamicAutoSync();

        })->everyMinute()->name('rhythm-gacha-auto-sync');
    }

    /**
     * 全员静默战绩巡检逻辑 (同时处理 osu 与 舞萌)
     */
    public function runDynamicAutoSync(): void
    {
        $staminaService = app(StaminaService::class);
        $config = $this->getConfig();
        $rewardMap = [
            'L5' => (int)($config['reward_gem_l5'] ?? 100),
            'L4' => (int)($config['reward_gem_l4'] ?? 75),
            'L3' => (int)($config['reward_gem_l3'] ?? 50),
            'L2' => (int)($config['reward_gem_l2'] ?? 25),
            'L1' => (int)($config['reward_gem_l1'] ?? 10),
        ];

        // 查找所有绑定了任一游戏账号的玩家
        $users = RyUser::whereNotNull('osu_uid')->orWhereNotNull('maimai_id')->get();

        foreach ($users as $user) {
            try {
                $staminaInfo = $staminaService->getRealtimeStamina($user, $config);
                $availableStamina = $user->is_admin ? 999 : (int)($staminaInfo['stamina'] ?? 0);

                // ============ 1. 自动同步 osu! ============
                if ($user->osu_uid) {
                    $osuDriver = new OsuDriver();
                    $res = $osuDriver->syncUserRecentScores($user->user_id, $user->osu_uid, $availableStamina, $rewardMap);

                    if (!empty($res['settled'])) {
                        $totalGems = 0;
                        foreach ($res['settled'] as $r) {
                            RyOsuScore::create($r);
                            $totalGems += $r['gems_awarded'];
                        }
                        if ($res['consumed_stamina'] > 0) {
                            $staminaService->consumeStamina($user, $config, $res['consumed_stamina']);
                            $availableStamina = max(0, $availableStamina - $res['consumed_stamina']);
                        }
                        $user->gems += $totalGems;
                        $user->save();
                    }
                }

                // ============ 2. 自动同步 舞萌 DX ============
                if ($user->maimai_id) {
                    $maiDriver = new MaimaiDriver();
                    $res = $maiDriver->syncUserRecords($user->user_id, $user->maimai_id, $availableStamina, $rewardMap);

                    if (!empty($res['settled'])) {
                        $totalGems = 0;
                        foreach ($res['settled'] as $r) {
                            RyMaimaiScore::create($r);
                            $totalGems += $r['gems_awarded'];
                        }
                        if ($res['consumed_stamina'] > 0) {
                            $staminaService->consumeStamina($user, $config, $res['consumed_stamina']);
                        }
                        $user->gems += $totalGems;
                        $user->save();
                    }
                }

            } catch (\Exception $e) {
                // 静默记录单用户巡检跳过
                Log::warning("RhythmGacha: 用户 [{$user->user_id}] 自动巡检跳过 - " . $e->getMessage());
            }
        }
    }
}