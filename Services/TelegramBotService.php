<?php

namespace Plugin\RhythmGacha\Services;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use Plugin\RhythmGacha\Models\RyUser;
use Plugin\RhythmGacha\Models\RyBackpack;
use Plugin\RhythmGacha\Models\RyOsuScore;
use Plugin\RhythmGacha\Models\RyHololiveScore;
use Plugin\RhythmGacha\Models\RyMaimaiScore;
use Plugin\RhythmGacha\Services\Game\OsuDriver;

class TelegramBotService
{
    private bool $enabled = false;
    private string $botToken = '';
    private string $webhookBaseUrl = '';
    private string $apiUrl = '';

    public function __construct()
    {
        $plugin = app(\App\Services\Plugin\PluginManager::class)->getEnabledPlugins()['rhythm_gacha'] ?? null;
        if ($plugin) {
            $this->initCustomConfig($plugin->getConfig());
        }
    }

    public function initCustomConfig(array $config): void
    {
        $this->enabled = (bool)($config['tg_bot_enable'] ?? false);
        $this->botToken = trim((string)($config['tg_bot_token'] ?? ''));
        $this->webhookBaseUrl = trim((string)($config['tg_webhook_base_url'] ?? ''));
        $this->apiUrl = "https://api.telegram.org/bot{$this->botToken}";
    }

    /**
     * 主动注册 Webhook 与 原生指令菜单 (由控制器主动调用)
     */
    public function registerWebhook(): array
    {
        if (!$this->enabled || empty($this->botToken) || empty($this->webhookBaseUrl)) {
            return ['ok' => false, 'description' => '请先在插件配置中开启机器人、填入Token和Webhook域名！'];
        }

        $fullWebhookUrl = rtrim($this->webhookBaseUrl, '/') . '/api/v1/rhythm-gacha/telegram/webhook';
        
        try {
            // 1. 设置 Webhook
            $res = Http::timeout(10)->post("{$this->apiUrl}/setWebhook", [
                'url' => $fullWebhookUrl
            ])->json();

            // 2. 注册左下角原生指令菜单 (绑定 [/] 按钮)
            Http::timeout(10)->post("{$this->apiUrl}/setMyCommands", [
                'commands' => json_encode([
                    ['command' => 'panel', 'description' => '🌌 唤醒律动终端主菜单 (仪表盘)'],
                    ['command' => 'login', 'description' => '🔑 账号绑定与连接向导'],
                    ['command' => 'start', 'description' => '🚀 启动服务与说明指南']
                ])
            ]);

            return $res ?? ['ok' => false];
        } catch (Exception $e) {
            return ['ok' => false, 'description' => $e->getMessage()];
        }
    }

    public function handleUpdate(array $update): void
    {
        if (!$this->enabled || empty($this->botToken)) return;

        try {
            if (isset($update['callback_query'])) {
                $this->handleCallbackQuery($update['callback_query']);
                return;
            }

            if (isset($update['message'])) {
                $this->handleMessage($update['message']);
                return;
            }
        } catch (\Throwable $e) {
            Log::error("RhythmGacha 独立Bot处理异常: " . $e->getMessage());
        }
    }

    private function handleMessage(array $message): void
    {
        $chatId = $message['chat']['id'] ?? null;
        $tgUserId = $message['from']['id'] ?? null;
        $msgId = $message['message_id'] ?? null;
        $text = trim($message['text'] ?? '');

        if (!$chatId || !$tgUserId) return;

        // ================= 1. 拦截直接发图片 (直接拒收并物理秒删) =================
        if (isset($message['photo'])) {
            if ($msgId) $this->callApi('deleteMessage', ['chat_id' => $chatId, 'message_id' => $msgId]);
            $this->sendRejectPhotoNotice($chatId, $tgUserId);
            return;
        }

        // ================= 2. 接收文件 (触发纯内存 hololive 出勤核验) =================
        if (isset($message['document'])) {
            if ($msgId) $this->callApi('deleteMessage', ['chat_id' => $chatId, 'message_id' => $msgId]);
            $this->processHoloDocumentUpload($chatId, $tgUserId, $message['document']);
            return;
        }

        // 核心安全优化：撤回用户发送的普通文本 (指令/密码等)
        if ($msgId) {
            $this->callApi('deleteMessage', ['chat_id' => $chatId, 'message_id' => $msgId]);
        }

        $userStateKey = "ry_tg_state_{$tgUserId}";
        $state = Cache::get($userStateKey);

        if ($state) {
            $this->handleStateInput($chatId, $tgUserId, $text, $state);
            Cache::forget($userStateKey);
            return;
        }

        if (in_array($text, ['/start', '/panel', '/rhythm'], true)) {
            $this->cleanOldMessagesAndRender($chatId, $tgUserId);
        } elseif ($text === '/login') {
            $this->sendLoginGuide($chatId, null);
        }
    }

    private function cleanOldMessagesAndRender(int $chatId, int $tgUserId): void
    {
        $historyKey = "ry_history_msgs_{$tgUserId}";
        $msgIds = Cache::get($historyKey, []);

        if (!empty($msgIds)) {
            foreach ($msgIds as $mId) {
                $this->callApi('deleteMessage', ['chat_id' => $chatId, 'message_id' => $mId]);
            }
            Cache::forget($historyKey);
        }

        $user = User::where('telegram_id', $tgUserId)->first();
        if (!$user) {
            $this->sendLoginGuide($chatId, null);
            return;
        }

        $this->renderMainMenu($chatId, null, $user);
    }

    public function renderMainMenu(int $chatId, ?int $messageId, User $user): void
    {
        $ryUser = RyUser::firstOrCreate(['user_id' => $user->id]);
        $staminaService = app(StaminaService::class);
        $plugin = app(\App\Services\Plugin\PluginManager::class)->getEnabledPlugins()['rhythm_gacha'] ?? null;
        $stamina = $staminaService->getRealtimeStamina($ryUser, $plugin ? $plugin->getConfig() : []);

        $remainingBytes = max(0, $user->transfer_enable - ($user->u + $user->d));
        $trafficStr = $remainingBytes >= 1099511627776 
            ? round($remainingBytes / 1099511627776, 2) . ' TB'
            : round($remainingBytes / 1073741824, 2) . ' GB';

        $isVip = ($ryUser->monthly_pass_expire && strtotime($ryUser->monthly_pass_expire) > time());
        $vipText = $isVip ? "🎫 HoloPassport 生效中 (至 " . date('m-d', strtotime($ryUser->monthly_pass_expire)) . ")" : "未激活月票 (上限10点)";

        $caption = "🌌 <b>REIA NEXT · 律动跃迁活动终端</b>\n"
                 . "━━━━━━━━━━━━━━━━━━\n"
                 . "👤 <b>账号</b>: <code>{$user->email}</code>\n"
                 . "🌐 <b>专线剩余</b>: <code>{$trafficStr}</code>\n"
                 . "💎 <b>宝石储备</b>: <code>{$ryUser->gems} 💎</code>\n"
                 . "⚡ <b>律动体力</b>: <code>{$stamina['stamina']} / {$stamina['max_stamina']}</code>\n"
                 . "⏳ <b>体力恢复</b>: " . ($stamina['stamina'] >= $stamina['max_stamina'] ? "已完全蓄满" : "下点恢复 {$stamina['seconds_to_next']}秒") . "\n"
                 . "🎖️ <b>月票特权</b>: {$vipText}\n"
                 . "━━━━━━━━━━━━━━━━━━\n"
                 . "🎯 <b>UP池垫抽</b>: <code>{$ryUser->pity_up}/90</code> | <b>大保底</b>: " . ($ryUser->is_next_up_guaranteed ? "已激活(必出UP)" : "50%可能歪") . "\n\n"
                 . "💡 <i>点击下方按钮快速完成出勤、查分与物资调度：</i>";

        $webUrl = $plugin ? $plugin->getConfig('gacha_web_url', 'https://next.rka.jp/gacha.html') : 'https://next.rka.jp/gacha.html';

        $buttons = [
            [
                ['text' => '📸 hololive 截图核验', 'callback_data' => 'ry_upload_holo_guide'],
                ['text' => '⚡ 手动同步成绩 (osu/舞萌)', 'callback_data' => 'ry_sync_select']
            ],
            [
                ['text' => '🎰 祈愿跃迁 (网页抽卡)', 'url' => $webUrl],
                ['text' => '🎒 战备物资背包', 'callback_data' => 'ry_backpack_0']
            ],
            [
                ['text' => '📜 出勤流水明细', 'callback_data' => 'ry_records_0'],
                ['text' => '🎫 购买/续期月票', 'callback_data' => 'ry_buy_pass']
            ],
            [
                ['text' => '🔗 绑定音游账号', 'callback_data' => 'ry_bind_menu'],
                ['text' => '🔄 刷新状态', 'callback_data' => 'ry_refresh_menu']
            ],
            [
                ['text' => '🚪 解除绑定', 'callback_data' => 'ry_unbind_confirm']
            ]
        ];

        $this->smoothEditOrSend($chatId, $messageId, $caption, $buttons, $user->telegram_id);
    }

    /**
     * 核心修复：平滑过渡函数 (解决 Telegram 图片消息无法转回文本消息的致命报错)
     */
    private function smoothEditOrSend(int $chatId, ?int $messageId, string $text, array $buttons, ?int $tgUserId = null): void
    {
        if ($messageId) {
            // 尝试原地直接编辑文本
            $res = $this->callApi('editMessageText', [
                'chat_id'      => $chatId,
                'message_id'   => $messageId,
                'text'         => $text,
                'parse_mode'   => 'HTML',
                'reply_markup' => ['inline_keyboard' => $buttons]
            ]);

            // 如果报错（如当前是图片卡片，Telegram 禁止直接转文本），自动降级：删除旧卡片并秒发新卡片！
            if (!($res['ok'] ?? false)) {
                $this->callApi('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
                $newRes = $this->callApi('sendMessage', [
                    'chat_id'      => $chatId,
                    'text'         => $text,
                    'parse_mode'   => 'HTML',
                    'reply_markup' => ['inline_keyboard' => $buttons]
                ]);
                if ($tgUserId && isset($newRes['result']['message_id'])) {
                    Cache::put("ry_history_msgs_{$tgUserId}", [$newRes['result']['message_id']], 86400);
                }
            }
        } else {
            $newRes = $this->callApi('sendMessage', [
                'chat_id'      => $chatId,
                'text'         => $text,
                'parse_mode'   => 'HTML',
                'reply_markup' => ['inline_keyboard' => $buttons]
            ]);
            if ($tgUserId && isset($newRes['result']['message_id'])) {
                Cache::put("ry_history_msgs_{$tgUserId}", [$newRes['result']['message_id']], 86400);
            }
        }
    }

    private function handleCallbackQuery(array $callback): void
    {
        $chatId = $callback['message']['chat']['id'];
        $messageId = $callback['message']['message_id'];
        $tgUserId = $callback['from']['id'];
        $data = $callback['data'] ?? '';

        if (!str_starts_with($data, 'ry_')) return;

        $this->callApi('answerCallbackQuery', ['callback_query_id' => $callback['id']]);

        $user = User::where('telegram_id', $tgUserId)->first();
        if (!$user && !str_starts_with($data, 'ry_login')) {
            $this->sendLoginGuide($chatId, $messageId);
            return;
        }

        switch (true) {
            // hololive 截图出勤向导
            case $data === 'ry_upload_holo_guide':
                $caption = "📸 <b>hololive Dreams 截图出勤核验指引</b>\n"
                         . "━━━━━━━━━━━━━━━━━━\n"
                         . "打完歌在结算画面直接截图（无需调音量条），并在 <b>60 秒内</b> 发送给机器人！\n\n"
                         . "⚠️ <b>上传硬性规范（防伪关键）</b>：\n"
                         . "1. 点击聊天框输入栏旁的 📎 <b>附件图标</b>；\n"
                         . "2. 选择 <b>【文件 (File / Document)】</b> 形式上传；\n"
                         . "3. <b>严禁直接发送普通照片</b>（直接发图会被 Telegram 压缩破坏底层时间戳与硬件指纹）！\n"
                         . "━━━━━━━━━━━━━━━━━━\n"
                         . "<i>现在请直接在此发送您的 PNG 原图文件：</i>";

                $buttons = [
                    [['text' => '🔙 取消并返回主菜单', 'callback_data' => 'ry_main_menu']]
                ];
                $this->smoothEditOrSend($chatId, $messageId, $caption, $buttons, $tgUserId);
                break;
            case $data === 'ry_main_menu' || $data === 'ry_refresh_menu':
                $this->renderMainMenu($chatId, $messageId, $user);
                break;

            case $data === 'ry_login_pwd':
                Cache::put("ry_tg_state_{$tgUserId}", 'await_pwd', 300);
                $this->smoothEditOrSend($chatId, $messageId, "🔑 <b>账号密码直连向导</b>\n\n请直接发送您的 Xboard 账号与密码（空格隔开）：\n<code>你的邮箱 你的密码</code>\n\n<i>为保护隐私，系统收到后将【立刻物理删除】该文本！</i>", [
                    [['text' => '🔙 取消操作', 'callback_data' => 'ry_login_cancel']]
                ], $tgUserId);
                break;

            case $data === 'ry_login_sub':
                Cache::put("ry_tg_state_{$tgUserId}", 'await_sub', 300);
                $this->smoothEditOrSend($chatId, $messageId, "🔗 <b>订阅链接直连向导</b>\n\n请直接发送您的 <b>完整订阅链接</b>：\n\n<i>系统提取 Token 关联后，将立刻物理销毁此条消息。</i>", [
                    [['text' => '🔙 取消操作', 'callback_data' => 'ry_login_cancel']]
                ], $tgUserId);
                break;

            case $data === 'ry_login_cancel':
                Cache::forget("ry_tg_state_{$tgUserId}");
                $this->sendLoginGuide($chatId, $messageId);
                break;

            case $data === 'ry_sync_select':
                $this->smoothEditOrSend($chatId, $messageId, "🥁 <b>选择出勤同步通道</b>\n\n请选择你要结算的音游平台：\n<i>系统自动扣除 1 点体力，核算新纪录并发放宝石（0体力仅记录不发钻）。</i>", [
                    [
                        ['text' => '🎮 同步 osu! (近24小时)', 'callback_data' => 'ry_do_sync_osu'],
                        ['text' => '🥁 同步 舞萌 DX (水鱼)', 'callback_data' => 'ry_do_sync_maimai']
                    ],
                    [['text' => '🔙 返回主菜单', 'callback_data' => 'ry_main_menu']]
                ], $tgUserId);
                break;

            case $data === 'ry_do_sync_osu' || $data === 'ry_do_sync_maimai':
                $gameType = str_replace('ry_do_sync_', '', $data);
                $this->executeSyncAndNotify($chatId, $messageId, $user, $gameType);
                break;

            case str_starts_with($data, 'ry_backpack_'):
                $page = (int)str_replace('ry_backpack_', '', $data);
                $this->renderBackpackPage($chatId, $messageId, $user, $page);
                break;

            case str_starts_with($data, 'ry_redeem_'):
                $backpackId = (int)str_replace('ry_redeem_', '', $data);
                $this->executeRedeemInTg($chatId, $messageId, $user, $backpackId);
                break;

            // ================= 核心修改 1：支持多游戏分类分页 (兼容 ry_records_0 与 ry_records_holo_0) =================
            case str_starts_with($data, 'ry_records_'):
                $raw = str_replace('ry_records_', '', $data);
                $parts = explode('_', $raw);
                if (count($parts) === 1) {
                    // 旧格式兜底: ry_records_0 默认查全部
                    $gameType = 'all';
                    $page = (int)$parts[0];
                } else {
                    // 新格式: ry_records_holo_0 / ry_records_osu_0 / ry_records_all_0
                    $gameType = $parts[0];
                    $page = (int)$parts[1];
                }
                $this->renderRecordsPage($chatId, $messageId, $user, $gameType, $page);
                break;

            // ================= 核心修改 2：支持 Holo/osu/舞萌 专属详情档案调度 (避免写死 osu) =================
            case str_starts_with($data, 'ry_detail_'):
                $raw = str_replace('ry_detail_', '', $data);
                $parts = explode('_', $raw);
                if (count($parts) === 1) {
                    $gameType = 'osu';
                    $logId = (int)$parts[0];
                } else {
                    $gameType = $parts[0];
                    $logId = (int)$parts[1];
                }
                $this->renderScoreDetail($chatId, $messageId, $user, $gameType, $logId);
                break;

            case $data === 'ry_bind_menu':
                $ryUser = RyUser::firstOrCreate(['user_id' => $user->id]);
                $caption = "🔗 <b>音游身份绑定设置</b>\n"
                         . "━━━━━━━━━━━━━━━━━━\n"
                         . "• <b>osu! 账号</b>: " . ($ryUser->osu_uid ? "<code>{$ryUser->osu_uid}</code>" : "<i>未绑定</i>") . "\n"
                         . "• <b>舞萌 DX</b>: " . ($ryUser->maimai_id ? "<code>{$ryUser->maimai_id}</code>" : "<i>未绑定</i>") . "\n"
                         . "━━━━━━━━━━━━━━━━━━\n"
                         . "请选择绑定方式：";

                $buttons = [
                    [
                        ['text' => '🌐 osu! 官方授权跳转', 'url' => "https://next.rka.jp/gacha.html"],
                        ['text' => '✍️ 手动绑定 osu!', 'callback_data' => 'ry_bind_input_osu']
                    ],
                    [
                        ['text' => '🥁 绑定水鱼 (舞萌DX)', 'callback_data' => 'ry_bind_input_mai']
                    ],
                    [['text' => '🔙 返回主菜单', 'callback_data' => 'ry_main_menu']]
                ];
                $this->smoothEditOrSend($chatId, $messageId, $caption, $buttons, $tgUserId);
                break;

            case $data === 'ry_bind_input_osu' || $data === 'ry_bind_input_mai':
                $type = $data === 'ry_bind_input_osu' ? 'osu' : 'maimai';
                Cache::put("ry_tg_state_{$tgUserId}", "await_bind_{$type}", 300);
                $this->smoothEditOrSend($chatId, $messageId, "✍️ <b>请输入你的 " . ($type === 'osu' ? 'osu! 用户名或纯数字 UID' : '水鱼用户名 / QQ号 / Import-Token') . "</b>：\n\n直接在聊天框发送即可，发送后自动销毁文本。", [
                    [['text' => '🔙 取消绑定', 'callback_data' => 'ry_bind_menu']]
                ], $tgUserId);
                break;

            case $data === 'ry_buy_pass':
                $this->renderBuyPassMenu($chatId, $messageId, $user);
                break;

            case $data === 'ry_pass_balance':
                $this->executeBuyPassBalance($chatId, $messageId, $user);
                break;

            case $data === 'ry_pass_epay_alipay' || $data === 'ry_pass_epay_wxpay':
                $channel = str_replace('ry_pass_epay_', '', $data);
                $this->generateEpayLinkAndSend($chatId, $messageId, $user, $channel);
                break;

            case $data === 'ry_unbind_confirm':
                $this->smoothEditOrSend($chatId, $messageId, "⚠️ <b>确认解除 Telegram 账户绑定？</b>\n\n解绑后机器人将不再响应您的操作，再次使用需重新 /login。", [
                    [
                        ['text' => '🛑 确认解绑', 'callback_data' => 'ry_do_unbind'],
                        ['text' => '🔙 取消', 'callback_data' => 'ry_main_menu']
                    ]
                ], $tgUserId);
                break;

            case $data === 'ry_do_unbind':
                $user->telegram_id = null;
                $user->save();
                $this->smoothEditOrSend($chatId, $messageId, "✅ <b>已成功解除绑定！</b>\n欢迎随时再次使用 /login 重新连接。", [], $tgUserId);
                break;
        }
    }

/**
     * 7. 战绩流水明细展示 (全面支持 hololive, osu!, 舞萌 DX 与分类筛选)
     */
    private function renderRecordsPage(int $chatId, int $messageId, User $user, string $gameType = 'all', int $page = 0): void
    {
        $pageSize = 4;
        $records = [];
        $total = 0;

        // ================= 1. 分类查询并格式化数据 =================
        // A. 查询 Hololive 战绩
        if ($gameType === 'holo' || $gameType === 'all') {
            $holoQuery = \Plugin\RhythmGacha\Models\RyHololiveScore::where('user_id', $user->id);
            if ($gameType === 'holo') $total = $holoQuery->count();
            
            $holoList = $holoQuery->orderBy('created_at', 'desc')->limit(20)->get()->map(function($item) {
                return [
                    'game'         => 'holo',
                    'tag'          => 'HOLO',
                    'id'           => $item->id,
                    'title'        => $item->song_title,
                    'rank'         => $item->rank,
                    'score_str'    => '得分: ' . number_format($item->score),
                    'metric'       => "MAX {$item->max_combo}x",
                    'gems'         => $item->gems_awarded,
                    'time'         => $item->created_at->format('m-d H:i'),
                    'timestamp'    => $item->created_at->timestamp
                ];
            });
            $records = array_merge($records, $holoList->toArray());
        }

        // B. 查询 osu! 战绩
        if ($gameType === 'osu' || $gameType === 'all') {
            $osuQuery = \Plugin\RhythmGacha\Models\RyOsuScore::where('user_id', $user->id);
            if ($gameType === 'osu') $total = $osuQuery->count();

            $osuList = $osuQuery->orderBy('played_at', 'desc')->limit(20)->get()->map(function($item) {
                return [
                    'game'         => 'osu',
                    'tag'          => 'OSU',
                    'id'           => $item->id,
                    'title'        => $item->song_title,
                    'rank'         => $item->rank,
                    'score_str'    => $item->score > 0 ? '得分: ' . number_format($item->score) : ($item->pp > 0 ? "PP: {$item->pp}" : 'Lazer 计分'),
                    'metric'       => 'ACC ' . round($item->accuracy * 100, 2) . '%',
                    'gems'         => $item->gems_awarded,
                    'time'         => $item->played_at->format('m-d H:i'),
                    'timestamp'    => $item->played_at->timestamp
                ];
            });
            $records = array_merge($records, $osuList->toArray());
        }

        // C. 查询 舞萌 DX 战绩
        if ($gameType === 'maimai' || $gameType === 'all') {
            $maiQuery = \Plugin\RhythmGacha\Models\RyMaimaiScore::where('user_id', $user->id);
            if ($gameType === 'maimai') $total = $maiQuery->count();

            $maiList = $maiQuery->orderBy('created_at', 'desc')->limit(20)->get()->map(function($item) {
                return [
                    'game'         => 'maimai',
                    'tag'          => 'MAI',
                    'id'           => $item->id,
                    'title'        => $item->song_title,
                    'rank'         => $item->rate,
                    'score_str'    => 'DX ' . number_format($item->dx_score),
                    'metric'       => "{$item->achievements}%",
                    'gems'         => $item->gems_awarded,
                    'time'         => $item->created_at->format('m-d H:i'),
                    'timestamp'    => $item->created_at->timestamp
                ];
            });
            $records = array_merge($records, $maiList->toArray());
        }

        // 全量按时间倒序排序
        usort($records, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);
        if ($gameType === 'all') $total = count($records);

        $totalPages = max(1, (int)ceil($total / $pageSize));
        $page = min(max(0, $page), $totalPages - 1);
        $currentSlice = array_slice($records, $page * $pageSize, $pageSize);

        // ================= 2. 组装卡片文本与分类按钮 =================
        $typeNames = ['all' => '全部', 'holo' => '📸 Hololive', 'osu' => '🎮 osu!', 'maimai' => '🥁 舞萌'];
        $typeName = $typeNames[$gameType] ?? '全部';

        $caption = "📜 <b>出勤战绩历史流水 · {$typeName} (第 " . ($page + 1) . "/{$totalPages} 页)</b>\n"
                 . "━━━━━━━━━━━━━━━━━━\n";

        if (empty($currentSlice)) {
            $caption .= "<i>该分类下暂无出勤流水记录！</i>\n";
        }

        $buttons = [];
        
        // 顶部快速分类切换按钮行
        $buttons[] = [
            ['text' => ($gameType === 'all' ? '🔘 全部' : '全部'), 'callback_data' => 'ry_records_all_0'],
            ['text' => ($gameType === 'holo' ? '🔘 Holo' : '📸 Holo'), 'callback_data' => 'ry_records_holo_0'],
            ['text' => ($gameType === 'osu' ? '🔘 osu!' : '🎮 osu!'), 'callback_data' => 'ry_records_osu_0'],
            ['text' => ($gameType === 'maimai' ? '🔘 舞萌' : '🥁 舞萌'), 'callback_data' => 'ry_records_maimai_0']
        ];

        // 渲染战绩条目
        foreach ($currentSlice as $r) {
            $gemStr = $r['gems'] > 0 ? "+{$r['gems']} 💎" : "0体力·无奖";
            
            $caption .= "• <b>[{$r['tag']}] {$r['title']}</b> ({$r['rank']}级)\n"
                      . "  {$r['metric']} | {$r['score_str']} | {$gemStr} | <code>{$r['time']}</code>\n\n";

            $buttons[] = [
                ['text' => "🔍 查看详情 [{$r['tag']}: {$r['title']}]", 'callback_data' => "ry_detail_{$r['game']}_{$r['id']}"]
            ];
        }
        $caption .= "━━━━━━━━━━━━━━━━━━";

        // 分页导航行
        $navRow = [];
        if ($page > 0) {
            $navRow[] = ['text' => '⬅️ 上一页', 'callback_data' => "ry_records_{$gameType}_" . ($page - 1)];
        }
        if ($page + 1 < $totalPages) {
            $navRow[] = ['text' => '下一页 ➡️', 'callback_data' => "ry_records_{$gameType}_" . ($page + 1)];
        }
        if (!empty($navRow)) $buttons[] = $navRow;

        $buttons[] = [['text' => '🔙 返回主菜单', 'callback_data' => 'ry_main_menu']];

        $this->smoothEditOrSend($chatId, $messageId, $caption, $buttons, $user->telegram_id);
    }

    /**
     * 8. 单曲详细档案总调度 (根据游戏类型分发到专属详情卡片)
     */
    private function renderScoreDetail(int $chatId, int $messageId, User $user, string $gameType, int $logId): void
    {
        if ($gameType === 'holo') {
            $this->renderHoloDetail($chatId, $messageId, $user, $logId);
            return;
        }

        if ($gameType === 'osu') {
            $this->renderScoreDetailWithCover($chatId, $messageId, $user, $logId);
            return;
        }

        if ($gameType === 'maimai') {
            $this->renderMaimaiDetail($chatId, $messageId, $user, $logId);
            return;
        }

        $this->renderRecordsPage($chatId, $messageId, $user, 'all', 0);
    }

    /**
     * 9. hololive Dreams 专属详细战绩卡片展示
     */
    private function renderHoloDetail(int $chatId, int $messageId, User $user, int $logId): void
    {
        $log = \Plugin\RhythmGacha\Models\RyHololiveScore::where('user_id', $user->id)->where('id', $logId)->first();
        if (!$log) {
            $this->renderRecordsPage($chatId, $messageId, $user, 'holo', 0);
            return;
        }

        $caption = "📸 <b>hololive Dreams 出勤核验档案</b>\n"
                 . "━━━━━━━━━━━━━━━━━━\n"
                 . "🎵 <b>曲目</b>: <b>{$log->song_title}</b>\n"
                 . "🎨 <b>曲师</b>: {$log->artist}\n"
                 . "⭐ <b>难度</b>: <code>{$log->difficulty} {$log->level_num}★</code>\n"
                 . "👤 <b>队长角色</b>: <code>{$log->leader_character}</code>\n"
                 . "━━━━━━━━━━━━━━━━━━\n"
                 . "🏆 <b>达成评级</b>: <code>{$log->rank} 级</code> " . ($log->is_new_record ? "(🎉 新纪录!)" : "") . "\n"
                 . "🎯 <b>本局得分</b>: <code>" . number_format($log->score) . "</code> (历史最高: " . number_format($log->hi_score) . ")\n"
                 . "🔥 <b>最大连击</b>: <code>{$log->max_combo}x</code>\n"
                 . "🔢 <b>判定详情</b>:\n"
                 . "   PERFECT: <code>{$log->perfect_count}</code> | GREAT: <code>{$log->great_count}</code>\n"
                 . "   GOOD: <code>{$log->good_count}</code> | BAD: <code>{$log->bad_count}</code> | MISS: <code>{$log->miss_count}</code>\n"
                 . "⏱️ <b>判定偏移</b>: FAST: <code>{$log->fast_count}</code> | SLOW: <code>{$log->slow_count}</code>\n"
                 . "💎 <b>结算入账</b>: <code>+{$log->gems_awarded} 宝石</code>\n"
                 . "⏰ <b>拍摄时戳</b>: <code>{$log->photo_time}</code>\n"
                 . "━━━━━━━━━━━━━━━━━━\n"
                 . "🛡️ <b>防伪哈希</b>: <code>" . substr($log->image_hash, 0, 16) . "...</code>";

        $buttons = [
            [['text' => '🔙 返回流水列表', 'callback_data' => 'ry_records_holo_0']],
            [['text' => '🌌 返回主菜单', 'callback_data' => 'ry_main_menu']]
        ];

        $this->smoothEditOrSend($chatId, $messageId, $caption, $buttons, $user->telegram_id);
    }

    /**
     * 10. osu! 详细战绩展示 (包含原装封面与平滑返回)
     */
    private function renderScoreDetailWithCover(int $chatId, int $messageId, User $user, int $logId): void
    {
        $log = \Plugin\RhythmGacha\Models\RyOsuScore::where('user_id', $user->id)->where('id', $logId)->first();
        if (!$log) {
            $this->renderRecordsPage($chatId, $messageId, $user, 'osu', 0);
            return;
        }

        $caption = "🎮 <b>osu! 战绩档案明细 · {$log->rank} 级达成</b>\n"
                 . "━━━━━━━━━━━━━━━━━━\n"
                 . "🎵 <b>曲目</b>: <b>{$log->song_title}</b>\n"
                 . "🎨 <b>曲师</b>: {$log->artist}\n"
                 . "⭐ <b>难度星级</b>: <code>{$log->difficulty_rating}★</code>\n"
                 . "━━━━━━━━━━━━━━━━━━\n"
                 . "📊 <b>准度 (ACC)</b>: <code>" . round($log->accuracy * 100, 2) . "%</code>\n"
                 . "🎯 <b>计分/指标</b>: <code>" . ($log->score > 0 ? number_format($log->score) : ($log->pp > 0 ? "{$log->pp} PP" : "Lazer 计分")) . "</code>\n"
                 . "🔥 <b>最大连击</b>: <code>{$log->max_combo}x</code>\n"
                 . "🔢 <b>判定详情</b>: 300:<code>{$log->count_300}</code> | 100:<code>{$log->count_100}</code> | 50:<code>{$log->count_50}</code> | MISS:<code>{$log->count_miss}</code>\n"
                 . "💎 <b>结算入账</b>: <code>+{$log->gems_awarded} 宝石</code>\n"
                 . "⏰ <b>完成时间</b>: <code>{$log->played_at}</code>";

        $buttons = [];
        if ($log->beatmap_url) {
            $buttons[] = [['text' => '🌐 在 osu! 官网打开原曲谱面', 'url' => $log->beatmap_url]];
        }
        $buttons[] = [
            ['text' => '🔙 返回流水列表', 'callback_data' => 'ry_records_osu_0'],
            ['text' => '🌌 返回主菜单', 'callback_data' => 'ry_main_menu']
        ];

        if ($log->cover_url) {
            $this->editMessageMediaWithMemoryStream($chatId, $messageId, $log->cover_url, $caption, $buttons);
        } else {
            $this->smoothEditOrSend($chatId, $messageId, $caption, $buttons, $user->telegram_id);
        }
    }

    /**
     * 11. 舞萌 DX 详细战绩档案展示
     */
    private function renderMaimaiDetail(int $chatId, int $messageId, User $user, int $logId): void
    {
        $log = \Plugin\RhythmGacha\Models\RyMaimaiScore::where('user_id', $user->id)->where('id', $logId)->first();
        if (!$log) {
            $this->renderRecordsPage($chatId, $messageId, $user, 'maimai', 0);
            return;
        }

        $caption = "🥁 <b>舞萌 DX 战绩档案明细</b>\n"
                 . "━━━━━━━━━━━━━━━━━━\n"
                 . "🎵 <b>曲目</b>: <b>{$log->song_title}</b>\n"
                 . "⭐ <b>谱面</b>: <code>{$log->song_type} [{$log->level_label} {$log->ds}]</code>\n"
                 . "🏆 <b>评级</b>: <code>{$log->rate} 级</code>\n"
                 . "📊 <b>达成率</b>: <code>{$log->achievements}%</code>\n"
                 . "🎯 <b>DX得分</b>: <code>" . number_format($log->dx_score) . "</code>\n"
                 . "✨ <b>奖牌状态</b>: <code>" . strtoupper("{$log->fc} {$log->fs}") . "</code>\n"
                 . "💎 <b>结算入账</b>: <code>+{$log->gems_awarded} 宝石</code>\n"
                 . "⏰ <b>同步时间</b>: <code>{$log->created_at}</code>";

        $buttons = [
            [['text' => '🔙 返回流水列表', 'callback_data' => 'ry_records_maimai_0']],
            [['text' => '🌌 返回主菜单', 'callback_data' => 'ry_main_menu']]
        ];

        $this->smoothEditOrSend($chatId, $messageId, $caption, $buttons, $user->telegram_id);
    }

    private function editMessageMediaWithMemoryStream(int $chatId, int $messageId, string $imageUrl, string $caption, array $buttons): void
    {
        try {
            $imgRes = Http::timeout(8)->get($imageUrl);
            if (!$imgRes->successful()) {
                $this->smoothEditOrSend($chatId, $messageId, $caption, $buttons);
                return;
            }

            $imgBinary = $imgRes->body();

            $postData = [
                'chat_id'    => $chatId,
                'message_id' => $messageId,
                'media'      => json_encode([
                    'type'       => 'photo',
                    'media'      => 'attach://cover_img',
                    'caption'    => $caption,
                    'parse_mode' => 'HTML'
                ]),
                'reply_markup' => json_encode(['inline_keyboard' => $buttons])
            ];

            Http::timeout(10)
                ->asMultipart()
                ->attach('cover_img', $imgBinary, 'cover.jpg')
                ->post("{$this->apiUrl}/editMessageMedia", $postData);

        } catch (Exception $e) {
            $this->smoothEditOrSend($chatId, $messageId, $caption, $buttons);
        }
    }

    private function renderBackpackPage(int $chatId, int $messageId, User $user, int $page = 0): void
    {
        $pageSize = 4;
        $total = RyBackpack::where('user_id', $user->id)->where('is_used', false)->count();
        $items = RyBackpack::with('item')
            ->where('user_id', $user->id)
            ->where('is_used', false)
            ->orderBy('id', 'desc')
            ->skip($page * $pageSize)
            ->take($pageSize)
            ->get();

        $totalPages = max(1, (int)ceil($total / $pageSize));

        $caption = "🎒 <b>战略物资仓库 (第 " . ($page + 1) . "/{$totalPages} 页)</b>\n"
                 . "━━━━━━━━━━━━━━━━━━\n";

        if ($items->isEmpty()) {
            $caption .= "<i>背包空空如也，出勤打歌攒宝石去祈愿吧！</i>\n";
        }

        $buttons = [];
        foreach ($items as $it) {
            $starStr = str_repeat('★', $it->item->star_level ?? 3);
            $expectVal = $it->item->value_min === $it->item->value_max 
                ? "{$it->item->value_min}MB" 
                : "{$it->item->value_min}~{$it->item->value_max}MB";
            
            $caption .= "• <b>{$it->item->name}</b> ({$starStr})\n"
                      . "  预估效益: <code>+{$expectVal}</code> 专线流量\n\n";

            $buttons[] = [
                ['text' => "⚡ 立即核销使用 [{$it->item->name}]", 'callback_data' => "ry_redeem_{$it->id}"]
            ];
        }
        $caption .= "━━━━━━━━━━━━━━━━━━";

        $navRow = [];
        if ($page > 0) $navRow[] = ['text' => '⬅️ 上一页', 'callback_data' => 'ry_backpack_' . ($page - 1)];
        if ($page + 1 < $totalPages) $navRow[] = ['text' => '下一页 ➡️', 'callback_data' => 'ry_backpack_' . ($page + 1)];
        if (!empty($navRow)) $buttons[] = $navRow;

        $buttons[] = [['text' => '🔙 返回主菜单', 'callback_data' => 'ry_main_menu']];

        $this->smoothEditOrSend($chatId, $messageId, $caption, $buttons, $user->telegram_id);
    }

    private function executeRedeemInTg(int $chatId, int $messageId, User $user, int $backpackId): void
    {
        try {
            $backpackService = app(BackpackService::class);
            $res = $backpackService->useItem($user->id, $backpackId);

            $caption = "🎉 <b>物资核销成功！</b>\n"
                     . "━━━━━━━━━━━━━━━━━━\n"
                     . "• 开启物品: <b>{$res['item_name']}</b>\n"
                     . "• 注入配额: <code>+{$res['reward_amount']} MB</code> 专线高速流量\n"
                     . "━━━━━━━━━━━━━━━━━━\n"
                     . "<i>配额已物理写入 Xboard 数据库，即刻生效！</i>";

            $buttons = [
                [['text' => '📦 继续整理背包', 'callback_data' => 'ry_backpack_0']],
                [['text' => '🔙 返回主菜单', 'callback_data' => 'ry_main_menu']]
            ];
            $this->smoothEditOrSend($chatId, $messageId, $caption, $buttons, $user->telegram_id);
        } catch (Exception $e) {
            $this->smoothEditOrSend($chatId, $messageId, "❌ 核销失败: {$e->getMessage()}", [
                [['text' => '🔙 返回背包', 'callback_data' => 'ry_backpack_0']]
            ], $user->telegram_id);
        }
    }

    public function executeSyncAndNotify(int $chatId, ?int $messageId, User $user, string $gameType): void
    {
        try {
            $ryUser = RyUser::where('user_id', $user->id)->first();
            $staminaService = app(StaminaService::class);
            $plugin = app(\App\Services\Plugin\PluginManager::class)->getEnabledPlugins()['rhythm_gacha'] ?? null;
            $config = $plugin ? $plugin->getConfig() : [];
            $staminaInfo = $staminaService->getRealtimeStamina($ryUser, $config);

            $availableStamina = $user->is_admin ? 999 : (int)($staminaInfo['stamina'] ?? 0);
            $rewardMap = [
                'L5' => (int)($config['reward_gem_l5'] ?? 100),
                'L4' => (int)($config['reward_gem_l4'] ?? 75),
                'L3' => (int)($config['reward_gem_l3'] ?? 50),
                'L2' => (int)($config['reward_gem_l2'] ?? 25),
                'L1' => (int)($config['reward_gem_l1'] ?? 10),
            ];

            if ($gameType === 'osu') {
                if (!$ryUser->osu_uid) throw new Exception("请先绑定 osu! 账号！");
                $driver = new OsuDriver();
                $res = $driver->syncUserRecentScores($user->id, $ryUser->osu_uid, $availableStamina, $rewardMap);

                if (empty($res['settled'])) {
                    $msg = "ℹ️ <b>未检测到过去 24 小时内的新通关战绩</b>（已记录成绩已自动跳过）。";
                    if ($messageId) $this->smoothEditOrSend($chatId, $messageId, $msg, [[['text' => '🔙 返回主菜单', 'callback_data' => 'ry_main_menu']]], $user->telegram_id);
                    return;
                }

                $totalGems = 0;
                $firstRecord = $res['settled'][0];
                foreach ($res['settled'] as $r) {
                    RyOsuScore::create($r);
                    $totalGems += $r['gems_awarded'];
                }
                if ($res['consumed_stamina'] > 0) {
                    $staminaService->consumeStamina($ryUser, $config, $res['consumed_stamina']);
                }
                $ryUser->gems += $totalGems;
                $ryUser->save();

                $caption = "🎉 <b>出勤结算完成！新斩获 +{$totalGems} 💎 宝石！</b>\n"
                         . "━━━━━━━━━━━━━━━━━━\n"
                         . "• <b>最新曲目</b>: {$firstRecord['song_title']}\n"
                         . "• <b>达成评级</b>: <code>{$firstRecord['rank']} 级</code> (ACC: " . round($firstRecord['accuracy']*100,2) . "%)\n"
                         . "• <b>本批结算</b>: 共 <code>" . count($res['settled']) . "</code> 首新歌 (消耗 {$res['consumed_stamina']} 体力)\n"
                         . ($res['invalid_count'] > 0 ? "• ⚠️ 另有 <code>{$res['invalid_count']}</code> 首因体力不足仅记录未发钻\n" : "")
                         . "━━━━━━━━━━━━━━━━━━";

                $buttons = [
                    [['text' => '📜 查看详细出勤档案', 'callback_data' => 'ry_detail_' . RyOsuScore::where('score_id', $firstRecord['score_id'])->value('id')]],
                    [['text' => '🔙 返回主菜单', 'callback_data' => 'ry_main_menu']]
                ];

                if (!empty($firstRecord['cover_url']) && $messageId) {
                    $this->editMessageMediaWithMemoryStream($chatId, $messageId, $firstRecord['cover_url'], $caption, $buttons);
                } elseif ($messageId) {
                    $this->smoothEditOrSend($chatId, $messageId, $caption, $buttons, $user->telegram_id);
                }
            }
        } catch (Exception $e) {
            if ($messageId) {
                $this->smoothEditOrSend($chatId, $messageId, "❌ <b>同步失败</b>: {$e->getMessage()}", [
                    [['text' => '🔙 返回主菜单', 'callback_data' => 'ry_main_menu']]
                ], $user->telegram_id);
            }
        }
    }

    private function renderBuyPassMenu(int $chatId, int $messageId, User $user): void
    {
        $plugin = app(\App\Services\Plugin\PluginManager::class)->getEnabledPlugins()['rhythm_gacha'] ?? null;
        $price = (float)($plugin ? $plugin->getConfig('monthly_pass_price', 9.9) : 9.9);

        $caption = "🎫 <b>HoloPassport 月票特权激活</b>\n"
                 . "━━━━━━━━━━━━━━━━━━\n"
                 . "• ⚡ <b>体力上限翻倍</b>：直接提升至 <b>20 点</b> 满血储备！\n"
                 . "• 🎁 <b>限定特权卡</b>：自动入库一张 5★ 专属纪念卡！\n"
                 . "• ⏳ <b>无限顺延</b>：有效期内购买自动累加 30 天！\n"
                 . "━━━━━━━━━━━━━━━━━━\n"
                 . "• <b>特惠开通价</b>: <code>¥" . sprintf('%.2f', $price) . " / 30天</code>\n"
                 . "• <b>账户余额</b>: <code>¥" . ($user->balance / 100) . "</code>\n"
                 . "━━━━━━━━━━━━━━━━━━\n"
                 . "请选择开通方式（绝不覆盖现有订阅套餐）：";

        $buttons = [
            [['text' => "🪙 使用 Xboard 余额开通 (¥{$price})", 'callback_data' => 'ry_pass_balance']],
            [
                ['text' => '📱 支付宝扫码', 'callback_data' => 'ry_pass_epay_alipay'],
                ['text' => '💚 微信扫码', 'callback_data' => 'ry_pass_epay_wxpay']
            ],
            [['text' => '🔙 返回主菜单', 'callback_data' => 'ry_main_menu']]
        ];

        $this->smoothEditOrSend($chatId, $messageId, $caption, $buttons, $user->telegram_id);
    }

    private function executeBuyPassBalance(int $chatId, int $messageId, User $user): void
    {
        $plugin = app(\App\Services\Plugin\PluginManager::class)->getEnabledPlugins()['rhythm_gacha'] ?? null;
        $priceYuan = (float)($plugin ? $plugin->getConfig('monthly_pass_price', 9.9) : 9.9);
        $priceCents = (int)round($priceYuan * 100);

        try {
            DB::transaction(function () use ($user, $priceCents, $priceYuan) {
                $dbUser = User::where('id', $user->id)->lockForUpdate()->first();
                if ($dbUser->balance < $priceCents) {
                    throw new Exception("余额不足！月票需 ¥{$priceYuan}，当前余额仅 ¥" . ($dbUser->balance / 100));
                }
                $dbUser->balance -= $priceCents;
                $dbUser->save();

                app(MonthlyPassService::class)->grantPass($user->id, 30, 'BALANCE_TG');
            });

            $this->smoothEditOrSend($chatId, $messageId, "🎉 <b>月票开通成功！</b>\n已通过账户余额扣除 ¥{$priceYuan}，HoloPassport 权益已顺延 30 天！", [
                [['text' => '🔙 返回主菜单', 'callback_data' => 'ry_main_menu']]
            ], $user->telegram_id);
        } catch (Exception $e) {
            $this->smoothEditOrSend($chatId, $messageId, "❌ 开通失败: {$e->getMessage()}", [
                [['text' => '🔙 返回月票菜单', 'callback_data' => 'ry_buy_pass']]
            ], $user->telegram_id);
        }
    }

    private function generateEpayLinkAndSend(int $chatId, int $messageId, User $user, string $channel): void
    {
        $plugin = app(\App\Services\Plugin\PluginManager::class)->getEnabledPlugins()['rhythm_gacha'] ?? null;
        $price = (float)($plugin ? $plugin->getConfig('monthly_pass_price', 9.9) : 9.9);

        $payment = \App\Models\Payment::where('payment', 'Epay')->where('enable', 1)->first();
        if (!$payment) {
            $this->smoothEditOrSend($chatId, $messageId, "❌ 系统未配置易支付网关，请改用余额开通！", [
                [['text' => '🔙 返回', 'callback_data' => 'ry_buy_pass']]
            ], $user->telegram_id);
            return;
        }

        $outTradeNo = 'PASS_' . $user->id . '_' . time();
        $cfg = $payment->config;
        $params = [
            'pid'          => $cfg['epay_pid'] ?? $cfg['pid'],
            'type'         => $channel,
            'out_trade_no' => $outTradeNo,
            'notify_url'   => url('/api/v1/rhythm-gacha/pass/epay/notify'),
            'return_url'   => url('/api/v1/rhythm-gacha/app'),
            'name'         => 'HoloPassport月票(30天)',
            'money'        => sprintf('%.2f', $price),
        ];
        ksort($params);
        $signStr = urldecode(http_build_query($params)) . ($cfg['epay_key'] ?? $cfg['key']);
        $params['sign'] = md5($signStr);
        $params['sign_type'] = 'MD5';
        $payUrl = rtrim($cfg['epay_url'] ?? $cfg['url'], '/') . '/submit.php?' . http_build_query($params);

        $caption = "💳 <b>HoloPassport 30天月票 · 易支付收银台</b>\n"
                 . "━━━━━━━━━━━━━━━━━━\n"
                 . "• <b>应付金额</b>: <code>¥" . sprintf('%.2f', $price) . "</code>\n"
                 . "• <b>通道类型</b>: " . ($channel === 'alipay' ? '支付宝' : '微信支付') . "\n"
                 . "• <b>有效时限</b>: 10 分钟\n"
                 . "━━━━━━━━━━━━━━━━━━\n"
                 . "<i>由于 Telegram 限制，请点击下方按钮打开浏览器完成支付，付款后权益自动秒级生效！</i>";

        $buttons = [
            [['text' => '🚀 点击打开浏览器立即支付', 'url' => $payUrl]],
            [['text' => '🔙 返回上级', 'callback_data' => 'ry_buy_pass']]
        ];

        $this->smoothEditOrSend($chatId, $messageId, $caption, $buttons, $user->telegram_id);
    }

    private function handleStateInput(int $chatId, int $tgUserId, string $input, string $state): void
    {
        if ($state === 'await_pwd') {
            $parts = preg_split('/\s+/', $input);
            if (count($parts) < 2) {
                $this->callApi('sendMessage', ['chat_id' => $chatId, 'text' => "❌ 格式有误，请输入：邮箱 密码 (用空格隔开)"]);
                return;
            }
            $user = User::where('email', $parts[0])->first();
            if (!$user || !password_verify($parts[1], $user->password)) {
                $this->callApi('sendMessage', ['chat_id' => $chatId, 'text' => "❌ 账号或密码错误，绑定失败！"]);
                return;
            }
            $user->telegram_id = $tgUserId;
            $user->save();

            $this->cleanOldMessagesAndRender($chatId, $tgUserId);
            return;
        }

        if ($state === 'await_sub') {
            parse_str(parse_url($input, PHP_URL_QUERY) ?? '', $queryParams);
            $token = $queryParams['token'] ?? null;
            if (!$token) {
                $this->callApi('sendMessage', ['chat_id' => $chatId, 'text' => "❌ 无法解析有效 Token，请发送完整正确的订阅链接！"]);
                return;
            }
            $user = User::where('token', $token)->first();
            if (!$user) {
                $this->callApi('sendMessage', ['chat_id' => $chatId, 'text' => "❌ 未找到对应用户，绑定失败！"]);
                return;
            }
            $user->telegram_id = $tgUserId;
            $user->save();

            $this->cleanOldMessagesAndRender($chatId, $tgUserId);
            return;
        }
    }

    private function sendLoginGuide(int $chatId, ?int $messageId): void
    {
        $text = "👋 <b>欢迎来到 REIA NEXT 律动跃迁活动中心</b>\n"
              . "━━━━━━━━━━━━━━━━━━\n"
              . "系统检测到您的 Telegram 账号尚未与 Xboard 账户绑定。\n"
              . "请选择以下方式之一完成快捷连接：";

        $buttons = [
            [['text' => '🔑 方式一：账号密码直连', 'callback_data' => 'ry_login_pwd']],
            [['text' => '🔗 方式二：粘贴订阅链接连接', 'callback_data' => 'ry_login_sub']]
        ];
        $this->smoothEditOrSend($chatId, $messageId, $text, $buttons);
    }

    private function callApi(string $endpoint, array $params = []): array
    {
        try {
            $res = Http::timeout(8)->post("{$this->apiUrl}/{$endpoint}", $params);
            return $res->json() ?? [];
        } catch (Exception $e) {
            return [];
        }
    }
    /**
     * 拒绝直接发送照片的警示卡片 (原地重绘单卡片)
     */
    private function sendRejectPhotoNotice(int $chatId, int $tgUserId): void
    {
        $lastMsgId = Cache::get("ry_last_msg_{$tgUserId}");
        
        $caption = "❌ <b>上传格式错误：拒绝直接发送照片！</b>\n"
                 . "━━━━━━━━━━━━━━━━━━\n"
                 . "Telegram 普通照片经过了有损压缩，<b>彻底抹除了底层 16 进制拍摄时戳与设备指纹</b>，无法完成防伪验算！\n\n"
                 . "👉 <b>正确操作规范</b>：\n"
                 . "1. 点击输入栏旁的 📎 <b>附件按钮</b>；\n"
                 . "2. 选择 <b>以【文件 (File / Document)】形式</b> 发送；\n"
                 . "3. 选中您的未压缩 PNG 原图发送！\n"
                 . "━━━━━━━━━━━━━━━━━━";

        $buttons = [
            [['text' => '🔄 重新查看指引', 'callback_data' => 'ry_upload_holo_guide']],
            [['text' => '🔙 返回主菜单', 'callback_data' => 'ry_main_menu']]
        ];

        $this->smoothEditOrSend($chatId, $lastMsgId, $caption, $buttons, $tgUserId);
    }

    /**
     * 全内存流下载、AI审计与就地单卡片结果反馈
     */
    private function processHoloDocumentUpload(int $chatId, int $tgUserId, array $doc): void
    {
        $user = User::where('telegram_id', $tgUserId)->first();
        $lastMsgId = Cache::get("ry_last_msg_{$tgUserId}");

        if (!$user) {
            $this->sendLoginGuide($chatId, $lastMsgId);
            return;
        }

        $fileName = $doc['file_name'] ?? 'screenshot.png';
        $fileSizeKb = round(($doc['file_size'] ?? 0) / 1024, 1);
        $mime = strtolower($doc['mime_type'] ?? '');

        // 严格后缀与 Mime 检验
        if (!str_ends_with(strtolower($fileName), '.png') && $mime !== 'image/png') {
            $caption = "❌ <b>文件类型不合规！</b>\n\n检测到文件为 <code>{$fileName}</code>。\nhololive 出勤仅接受未经压缩的原始 <b>PNG 格式截图文件</b>！";
            $this->smoothEditOrSend($chatId, $lastMsgId, $caption, [[['text' => '🔙 返回重试', 'callback_data' => 'ry_upload_holo_guide']]], $tgUserId);
            return;
        }

        // 核心：原地将卡片就地变更为【正在审核】状态！
        $loadingCaption = "⏳ <b>正在进行 AI 多模态全内存深度核验...</b>\n"
                        . "━━━━━━━━━━━━━━━━━━\n"
                        . "📦 <b>截获文件</b>: <code>{$fileName}</code> ({$fileSizeKb} KB)\n"
                        . "⚙️ <b>正在调度</b>: 纯内存二进制流传输 ➔ 16进制Chunk时钟 ➔ 双维度哈希 ➔ 谱面自洽验算...\n"
                        . "━━━━━━━━━━━━━━━━━━\n"
                        . "<i>⚡ 纯内存处理中，服务器硬盘零写入，请稍候 3~8 秒...</i>";

        $this->smoothEditOrSend($chatId, $lastMsgId, $loadingCaption, [], $tgUserId);
        
        // 重新获取当前卡片的 message_id 确保后续在同一卡片上重绘
        $activeCardId = Cache::get("ry_last_msg_{$tgUserId}");

        try {
            // 1. 获取 Telegram 文件下载路径
            $fileRes = $this->callApi('getFile', ['file_id' => $doc['file_id']]);
            $filePath = $fileRes['result']['file_path'] ?? null;
            if (!$filePath) {
                throw new Exception("无法从 Telegram 节点拉取文件传输流！");
            }

            // 2. 纯内存拉取二进制数据 (严禁保存到磁盘，0 磁盘开销！)
            $downloadUrl = "https://api.telegram.org/file/bot{$this->botToken}/{$filePath}";
            $imgBinary = Http::timeout(25)->get($downloadUrl)->body();

            if (empty($imgBinary)) {
                throw new Exception("文件流读取为空！");
            }

            // 3. 调度全内存视觉驱动进行多模态审计
            $visionService = app(\Plugin\RhythmGacha\Services\Game\HololiveVisionService::class);
            $result = $visionService->auditAndSettle($user->id, $imgBinary);

            // ================= 审核成功：同一张卡片就地重绘为胜利喜报 =================
            $gemStr = $result['gems_earned'] > 0 ? "+{$result['gems_earned']} 💎 宝石" : "0 💎 (体力不足已占位记录)";
            $staminaStr = $result['has_stamina'] ? "消耗 1 点律动体力" : "体力耗尽 (未发钻防囤积)";

            $successCaption = "🎉 <b>hololive 出勤核验成功！</b>\n"
                            . "━━━━━━━━━━━━━━━━━━\n"
                            . "🎵 <b>通关曲目</b>: <b>{$result['song_title']}</b>\n"
                            . "🏆 <b>达成评级</b>: <code>{$result['rank']} 级</code>\n"
                            . "🎯 <b>单局得分</b>: <code>" . number_format($result['score']) . "</code>\n"
                            . "💎 <b>结算入账</b>: <code>{$gemStr}</code>\n"
                            . "⚡ <b>体力状态</b>: <code>{$staminaStr}</code>\n"
                            . "⏰ <b>拍摄时戳</b>: <code>{$result['photo_time']}</code>\n"
                            . "━━━━━━━━━━━━━━━━━━\n"
                            . "<i>✅ 物理时序、双维度哈希指纹与击打守恒校验全部通过！</i>";

            $buttons = [
                [['text' => '📜 查看出勤流水', 'callback_data' => 'ry_records_holo_0']],
                [['text' => '🔙 返回主菜单', 'callback_data' => 'ry_main_menu']]
            ];

            $this->smoothEditOrSend($chatId, $activeCardId, $successCaption, $buttons, $tgUserId);

        } catch (\Throwable $e) {
            // ================= 审核失败/作弊警告：同一张卡片就地显示红字诊断 =================
            $logs = isset($visionService) ? $visionService->getLogs() : [];
            $lastStep = !empty($logs) ? end($logs) : "物理层握手异常";

            $failCaption = "❌ <b>hololive 出勤核验未通过</b>\n"
                         . "━━━━━━━━━━━━━━━━━━\n"
                         . "⚠️ <b>作弊拦截 / 驳回原因</b>:\n"
                         . "<code>" . htmlspecialchars($e->getMessage()) . "</code>\n\n"
                         . "🔍 <b>卡死断点步骤</b>:\n"
                         . "<code>" . htmlspecialchars($lastStep) . "</code>\n"
                         . "━━━━━━━━━━━━━━━━━━\n"
                         . "💡 <i>防伪规则提示：必须在打歌 60 秒内上传原图，严禁二次套娃截屏、挂机扫荡或篡改击打数据。</i>";

            $buttons = [
                [['text' => '🔄 重新上传原图', 'callback_data' => 'ry_upload_holo_guide']],
                [['text' => '🔙 返回主菜单', 'callback_data' => 'ry_main_menu']]
            ];

            $this->smoothEditOrSend($chatId, $activeCardId, $failCaption, $buttons, $tgUserId);
        }
    }
}