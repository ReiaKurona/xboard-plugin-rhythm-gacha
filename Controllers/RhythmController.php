<?php

namespace Plugin\RhythmGacha\Controllers;

use App\Http\Controllers\PluginController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Exception;
use Plugin\RhythmGacha\Services\StaminaService;
use Plugin\RhythmGacha\Services\GachaService;
use Plugin\RhythmGacha\Services\BackpackService;
use Plugin\RhythmGacha\Services\Game\OsuDriver;
use Plugin\RhythmGacha\Services\Game\MaimaiDriver;
use Plugin\RhythmGacha\Models\RyUser;
use Plugin\RhythmGacha\Models\RyBackpack;
use Plugin\RhythmGacha\Models\RyOsuScore;
use Plugin\RhythmGacha\Models\RyMaimaiScore;

class RhythmController extends PluginController
{
    private function getRyUser($userId)
    {
        return RyUser::firstOrCreate(['user_id' => $userId]);
    }

    /**
     * 1. 首页面板状态加载 (带月票状态、管理员权限与资产)
     */
    public function getInfo(Request $request, StaminaService $staminaService)
    {
        // 官方规范：检查插件是否启用
        if ($error = $this->beforePluginAction()) {
            return $error[1];
        }

        $userId = $request->user()->id;
        $ryUser = $this->getRyUser($userId);
        
        // 直查 v2_user 确认真实管理员身份
        $isAdmin = (bool)(\App\Models\User::where('id', $userId)->value('is_admin') ?? false);

        // 计算实时体力
        $staminaData = $staminaService->getRealtimeStamina($ryUser, $this->getConfig());
        // 1. 读取后台配置的 Markdown
        $tutorialMd = (string)$this->getConfig('tutorial_markdown', '');

        // 2. 如果后台配置为空，直接读取插件本地 resources/tutorial.md 真实原文件！
        if (empty(trim($tutorialMd))) {
            $localMdPath = __DIR__ . '/../resources/tutorial.md';
            if (file_exists($localMdPath)) {
                $tutorialMd = file_get_contents($localMdPath);
            }
        }

        // 核心替换点：返回给前端的数据包 (加入了 monthly_pass 详情)
        return $this->success([
            'is_admin' => $isAdmin,
            'gems' => $ryUser->gems,
            'pity_up' => $ryUser->pity_up,
            'is_guaranteed' => $ryUser->is_next_up_guaranteed,
            'monthly_pass' => [
                'is_active' => ($ryUser->monthly_pass_expire && strtotime($ryUser->monthly_pass_expire) > time()),
                'expire_at' => $ryUser->monthly_pass_expire ? date('Y-m-d H:i:s', strtotime($ryUser->monthly_pass_expire)) : null,
                'days_left' => ($ryUser->monthly_pass_expire && strtotime($ryUser->monthly_pass_expire) > time()) 
                    ? ceil((strtotime($ryUser->monthly_pass_expire) - time()) / 86400) : 0
            ],
            'stamina_info' => $staminaData,
            'bound_osu' => $ryUser->osu_uid,
            'bound_maimai' => $ryUser->maimai_id,
            'tutorial_markdown' => $tutorialMd // 真实回传
        ]);
    }

    /**
     * 2. 同步战绩发放宝石 (同时完美支持 osu! 与 舞萌 DX)
     */
    public function syncGameScore(Request $request, StaminaService $staminaService)
    {
        if ($error = $this->beforePluginAction()) return $error[1];

        $userId = $request->user()->id;
        $gameType = $request->input('game_type', 'maimai');
        $pluginConfig = $this->getConfig();

        if (!in_array($gameType, ['osu', 'maimai'])) {
            return $this->fail([400, "不支持的游戏通道"]);
        }

        try {
            return DB::transaction(function () use ($userId, $gameType, $staminaService, $pluginConfig) {
                $ryUser = RyUser::where('user_id', $userId)->lockForUpdate()->first();
                $staminaInfo = $staminaService->getRealtimeStamina($ryUser, $pluginConfig);

                // 【核心去除】：不再直接抛出异常！即使体力为0也继续执行，把歌曲刷进数据库占位防止囤积！
                $availableStamina = $ryUser->is_admin ? 999 : (int)($staminaInfo['stamina'] ?? 0);

                $rewardMap = [
                    'L5' => (int)($pluginConfig['reward_gem_l5'] ?? 100),
                    'L4' => (int)($pluginConfig['reward_gem_l4'] ?? 75),
                    'L3' => (int)($pluginConfig['reward_gem_l3'] ?? 50),
                    'L2' => (int)($pluginConfig['reward_gem_l2'] ?? 25),
                    'L1' => (int)($pluginConfig['reward_gem_l1'] ?? 10),
                ];

                $totalGems = 0;
                $consumedStamina = 0;
                $invalidCount = 0;

                // ================= 分支 A：osu! 结算 =================
                if ($gameType === 'osu') {
                    if (!$ryUser->osu_uid) throw new Exception("请先绑定 osu! 账号！");

                    $driver = new OsuDriver();
                    $res = $driver->syncUserRecentScores($userId, $ryUser->osu_uid, $availableStamina, $rewardMap);

                    if (empty($res['settled'])) {
                        return $this->success([
                            'message' => '未检测到过去 24 小时内的新记录（已记录曲目已自动过滤）。',
                            'settled_count' => 0
                        ]);
                    }

                    foreach ($res['settled'] as $r) {
                        RyOsuScore::create($r);
                        $totalGems += $r['gems_awarded'];
                    }
                    $consumedStamina = $res['consumed_stamina'];
                    $invalidCount = $res['invalid_count'] ?? 0;
                }

                // ================= 分支 B：舞萌 DX 结算 =================
                if ($gameType === 'maimai') {
                    if (!$ryUser->maimai_id) throw new Exception("请先绑定舞萌 DX 查分器用户名或QQ！");

                    $driver = new MaimaiDriver();
                    $res = $driver->syncUserRecords($userId, $ryUser->maimai_id, $availableStamina, $rewardMap);

                    if (empty($res['settled'])) {
                        return $this->success([
                            'message' => '未检索到新突破战绩（已记录曲目已自动过滤）。',
                            'settled_count' => 0
                        ]);
                    }

                    foreach ($res['settled'] as $r) {
                        RyMaimaiScore::create($r);
                        $totalGems += $r['gems_awarded'];
                    }
                    $consumedStamina = $res['consumed_stamina'];
                    $invalidCount = $res['invalid_count'] ?? 0;
                }

                // 只有实际扣了体力才调用扣除函数
                if ($consumedStamina > 0) {
                    $staminaService->consumeStamina($ryUser, $pluginConfig, $consumedStamina);
                }

                $ryUser->gems += $totalGems;
                $ryUser->save();

                $msg = "同步完成！成功结算 {$consumedStamina} 首 (+{$totalGems} 💎)";
                if ($invalidCount > 0) {
                    $msg .= "；另有 {$invalidCount} 首因【体力不足】仅完成记录，未发放奖励！";
                }

                return $this->success([
                    'message'          => $msg,
                    'settled_count'    => $consumedStamina,
                    'invalid_count'    => $invalidCount,
                    'gems_earned'      => $totalGems
                ]);
            });
        } catch (Exception $e) {
            return $this->fail([500, $e->getMessage()]);
        }
    }

    /**
     * 3. 抽卡 (支持传入具体 pool_code)
     */
    public function pullGacha(Request $request, GachaService $gachaService)
    {
        if ($error = $this->beforePluginAction()) return $error[1];

        $userId = $request->user()->id;
        $pullCount = (int)$request->input('count', 1);
        $poolCode = $request->input('pool_code', 'up_void'); // 默认为 UP 池

        if (!in_array($pullCount, [1, 10])) return $this->fail([400, "仅支持单抽或十连！"]);

        try {
            $results = $gachaService->pull($userId, $pullCount, $poolCode);
            return $this->success([
                'message' => '抽卡成功',
                'pull_results' => $results
            ]);
        } catch (Exception $e) {
            return $this->fail([500, $e->getMessage()]);
        }
    }

    /**
     * 4. 获取背包列表
     */
    public function getBackpack(Request $request)
    {
        if ($error = $this->beforePluginAction()) return $error[1];

        $userId = $request->user()->id;
        $items = RyBackpack::with('item')
                    ->where('user_id', $userId)
                    ->where('is_used', false)
                    ->orderBy('id', 'desc')
                    ->get();

        return $this->success(['items' => $items]);
    }

    /**
     * 5. 核销道具 (带全链路运算日志输出)
     */
    public function redeemItem(Request $request, BackpackService $backpackService)
    {
        if ($error = $this->beforePluginAction()) return $error[1];

        $userId = $request->user()->id;
        $backpackId = (int)$request->input('backpack_id');

        try {
            $result = $backpackService->useItem($userId, $backpackId);
            return $this->success([
                'message'       => "核销成功！开出 {$result['reward_amount']} MB 专线流量。",
                'item_name'     => $result['item_name'],
                'reward_amount' => (int)$result['reward_amount'],
                'reward_type'   => $result['reward_type'],
                'calc_details'  => $result['calc_details'] // 将完整计算日志送往前端
            ]);
        } catch (Exception $e) {
            return $this->fail([500, $e->getMessage()]);
        }
    }

    /**
     * 6. 绑定游戏账号 (加入水鱼即时身份核验与名片回显)
     */
    public function bindAccount(Request $request)
    {
        if ($error = $this->beforePluginAction()) return $error[1];

        $userId = $request->user()->id;
        $gameType = $request->input('game_type');
        $identifier = trim($request->input('identifier', ''));

        if (!in_array($gameType, ['osu', 'maimai'])) {
            return $this->fail([400, "不支持的游戏类型"]);
        }

        if (empty($identifier)) {
            return $this->fail([400, "账号/标识符不能为空"]);
        }

        $ryUser = $this->getRyUser($userId);

        try {
            if ($gameType === 'osu') {
                $ryUser->osu_uid = $identifier;
                $ryUser->save();
                return $this->success(['message' => "osu! 账号已保存: {$identifier}"]);
            }

            if ($gameType === 'maimai') {
                // 核心：调用水鱼驱动即时验明正身！
                $driver = new MaimaiDriver();
                $profile = $driver->verifyAndGetProfile($identifier);

                // 绑定并持久化
                $ryUser->maimai_id = $identifier;
                $ryUser->save();

                return $this->success([
                    'message' => "🎉 水鱼身份核验通过！已绑定玩家【{$profile['nickname']}】(DX Rating: {$profile['rating']})",
                    'profile' => $profile
                ]);
            }
        } catch (\Exception $e) {
            return $this->fail([400, $e->getMessage()]);
        }
    }

 /**
     * 7. 管理员测试发钻 (直查数据库鉴权，杜绝越权)
     */
    public function claimTestGems(Request $request)
    {
        if ($error = $this->beforePluginAction()) return $error[1];

        $userId = $request->user()->id;
        
        // 直查 v2_user 表
        $isAdmin = (bool)(\App\Models\User::where('id', $userId)->value('is_admin') ?? false);
        if (!$isAdmin) {
            return $this->fail([403, "权限不足：仅系统管理员可使用测试发钻功能！"]);
        }

        $ryUser = $this->getRyUser($userId);
        $ryUser->gems += 2500;
        $ryUser->save();

        return $this->success([
            'message' => "测试宝石已注入！(+2500 💎)",
            'current_gems' => $ryUser->gems
        ]);
    }

    /**
     * 8. 获取出勤战绩历史 (支持全量历史、分页、自定义每页条数)
     */
    public function getRecords(Request $request)
    {
        if ($error = $this->beforePluginAction()) return $error[1];

        $userId = $request->user()->id;
        $gameType = $request->input('game_type', 'all');
        $page = max(1, (int)$request->input('page', 1));
        $limit = min(50, max(1, (int)$request->input('limit', 10))); // 允许 5/10/20，上限50

        $records = [];

        // 1. osu! 全量流水
        if ($gameType === 'osu' || $gameType === 'all') {
            $osuList = \Plugin\RhythmGacha\Models\RyOsuScore::where('user_id', $userId)
                ->orderBy('played_at', 'desc')->get()->map(function($item) {
                    return [
                        'game'              => 'osu',
                        'title'             => $item->song_title,
                        'sub_title'         => $item->artist,
                        'cover_url'         => $item->cover_url,
                        'beatmap_url'       => $item->beatmap_url,
                        'rank'              => $item->rank,
                        'score'             => (int)$item->score,
                        'pp'                => (float)$item->pp,
                        'score_str'         => $item->score > 0 ? '得分: ' . number_format($item->score) : ($item->pp > 0 ? "PP: {$item->pp}" : 'Lazer 计分'),
                        'metric'            => 'ACC ' . round($item->accuracy * 100, 2) . '%',
                        'detail'            => "{$item->difficulty_rating}★ | Combo {$item->max_combo}",
                        'gems'              => (int)$item->gems_awarded,
                        'time'              => $item->played_at ? $item->played_at->format('Y-m-d H:i:s') : '',
                        'timestamp'         => $item->played_at ? $item->played_at->timestamp : 0,
                        'count_300'         => $item->count_300,
                        'count_100'         => $item->count_100,
                        'count_50'          => $item->count_50,
                        'count_miss'        => $item->count_miss,
                        'max_combo'         => $item->max_combo,
                        'difficulty_rating' => $item->difficulty_rating,
                    ];
                });
            $records = array_merge($records, $osuList->toArray());
        }

        // 2. 舞萌 DX 全量流水
        if ($gameType === 'maimai' || $gameType === 'all') {
            $maiList = \Plugin\RhythmGacha\Models\RyMaimaiScore::where('user_id', $userId)
                ->orderBy('created_at', 'desc')->get()->map(function($item) {
                    return [
                        'game'         => 'maimai',
                        'title'        => $item->song_title,
                        'sub_title'    => "{$item->song_type} [{$item->level_label} {$item->ds}]",
                        'cover_url'    => null,
                        'beatmap_url'  => null,
                        'rank'         => $item->rate,
                        'score_str'    => 'DX ' . number_format($item->dx_score),
                        'metric'       => "{$item->achievements}%",
                        'detail'       => strtoupper("{$item->fc} {$item->fs}"),
                        'gems'         => (int)$item->gems_awarded,
                        'time'         => $item->created_at ? $item->created_at->format('Y-m-d H:i:s') : '',
                        'timestamp'    => $item->created_at ? $item->created_at->timestamp : 0,
                        'dx_score'     => $item->dx_score,
                        'achievements' => $item->achievements,
                        'fc'           => $item->fc,
                        'fs'           => $item->fs,
                        'ds'           => $item->ds,
                    ];
                });
            $records = array_merge($records, $maiList->toArray());
        }

        // 3. hololive Dreams 全量流水
        if ($gameType === 'hololive' || $gameType === 'all') {
            $holoList = \Plugin\RhythmGacha\Models\RyHololiveScore::where('user_id', $userId)
                ->orderBy('created_at', 'desc')->get()->map(function($item) {
                    return [
                        'game'             => 'hololive',
                        'title'            => $item->song_title,
                        'sub_title'        => "{$item->artist} [{$item->difficulty} {$item->level_num}]",
                        'cover_url'        => null,
                        'beatmap_url'      => null,
                        'rank'             => $item->rank,
                        'score_str'        => '得分: ' . number_format($item->score),
                        'metric'           => "MAX {$item->max_combo}x",
                        'detail'           => "P:{$item->perfect_count} G:{$item->great_count} M:{$item->miss_count} | 队长: {$item->leader_character}",
                        'gems'             => (int)$item->gems_awarded,
                        'time'             => $item->created_at ? $item->created_at->format('Y-m-d H:i:s') : '',
                        'timestamp'        => $item->created_at ? $item->created_at->timestamp : 0,
                        'perfect'          => $item->perfect_count,
                        'great'            => $item->great_count,
                        'good'             => $item->good_count,
                        'bad'              => $item->bad_count,
                        'miss'             => $item->miss_count,
                        'fast'             => $item->fast_count,
                        'slow'             => $item->slow_count,
                        'score'            => $item->score,
                        'hi_score'         => $item->hi_score,
                        'leader_character' => $item->leader_character,
                        'difficulty'       => $item->difficulty,
                        'level_num'        => $item->level_num,
                    ];
                });
            $records = array_merge($records, $holoList->toArray());
        }

        // 按真实时间倒序排序
        usort($records, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);

        $total = count($records);
        $totalPages = max(1, (int)ceil($total / $limit));
        $offset = ($page - 1) * $limit;
        $pageSlice = array_slice($records, $offset, $limit);

        return $this->success([
            'records'      => $pageSlice,
            'total'        => $total,
            'current_page' => $page,
            'last_page'    => $totalPages,
            'limit'        => $limit
        ]);
    }

    /**
     * 9. 生成 osu! 官方授权跳转链接
     */
    public function getOsuAuthorizeUrl(Request $request)
    {
        if ($error = $this->beforePluginAction()) return $error[1];
        
        $clientId = $this->getConfig('osu_api_client_id');
        if (!$clientId) return $this->fail([400, "管理员未配置 osu_api_client_id"]);

        $userId = $request->user()->id;
        // 回调地址：带上当前用户的 Token 作为 state 校验
        $redirectUri = url('/api/v1/rhythm-gacha/oauth/osu/callback');

        $params = http_build_query([
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => 'identify public',
            'state'         => base64_encode(json_encode(['user_id' => $userId]))
        ]);

        return $this->success([
            'url' => "https://osu.ppy.sh/oauth/authorize?{$params}"
        ]);
    }

    /**
     * 10. osu! 授权完成后的官方回调处理
     */
    public function handleOsuCallback(Request $request)
    {
        $code = $request->input('code');
        $state = json_decode(base64_decode($request->input('state', '')), true);
        $userId = $state['user_id'] ?? null;

        if (!$code || !$userId) {
            return response("授权参数失效，请重新发起绑定", 400);
        }

        try {
            $clientId = $this->getConfig('osu_api_client_id');
            $clientSecret = $this->getConfig('osu_api_client_secret');
            $redirectUri = url('/api/v1/rhythm-gacha/oauth/osu/callback');

            // 1. 用 code 换取用户个人的 Access Token
            $tokenRes = \Illuminate\Support\Facades\Http::asForm()->post('https://osu.ppy.sh/oauth/token', [
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'code'          => $code,
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => $redirectUri
            ]);

            if (!$tokenRes->successful()) {
                return response("换取 osu Token 失败: " . $tokenRes->body(), 400);
            }

            $userAccessToken = $tokenRes->json('access_token');

            // 2. 用 Token 获取该玩家在官网的真实数字 UID 和 用户名
            $meRes = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => "Bearer {$userAccessToken}",
                'Accept' => 'application/json'
            ])->get('https://osu.ppy.sh/api/v2/me');

            if (!$meRes->successful()) {
                return response("读取 osu 个人资料失败", 400);
            }

            $osuData = $meRes->json();
            $numericId = (string)$osuData['id'];
            $username = $osuData['username'];

           // 3. 永久绑定入库
            $ryUser = \Plugin\RhythmGacha\Models\RyUser::firstOrCreate(['user_id' => $userId]);
            $ryUser->osu_uid = $numericId;
            $ryUser->save();

            // 4. 核心优化：返回跨窗口通信代码，自动关闭授权小弹窗并通知主页面！
            return response("
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset='UTF-8'>
                    <title>osu! 授权成功</title>
                </head>
                <body style='background:#0b0f19; color:#fff; font-family:system-ui, sans-serif; display:flex; align-items:center; justify-content:center; height:100vh; margin:0;'>
                    <div style='text-align:center; padding:24px; background:#151d30; border-radius:16px; border:1px solid #38bdf8;'>
                        <div style='font-size:36px; margin-bottom:12px;'>🎉</div>
                        <h2 style='margin:0 0 8px 0; color:#34d399;'>osu! 官方授权绑定成功！</h2>
                        <p style='color:#94a3b8; font-size:14px;'>已关联玩家: <strong style='color:#fff;'>{$username}</strong> (UID: {$numericId})</p>
                        <p style='font-size:12px; color:#64748b; margin-top:16px;'>窗口将在 1 秒后自动关闭...</p>
                    </div>
                    <script>
                        // 核心：通过跨窗口消息通知原弹窗/iframe，并自动关闭自身
                        if (window.opener) {
                            try {
                                window.opener.postMessage({ type: 'OSU_AUTH_SUCCESS', username: '{$username}', uid: '{$numericId}' }, '*');
                            } catch(e) {}
                            setTimeout(() => { window.close(); }, 1200);
                        } else {
                            setTimeout(() => { window.location.href = '/api/v1/rhythm-gacha/app'; }, 1500);
                        }
                    </script>
                </body>
                </html>
            ");

        } catch (\Exception $e) {
            return response("绑定异常: " . $e->getMessage(), 500);
        }
    }

    /**
     * 获取所有可用的卡池配置 (供前端动态渲染卡池列表)
     */
    public function getPools(Request $request)
    {
        if ($error = $this->beforePluginAction()) return $error[1];

        $pools = \Plugin\RhythmGacha\Models\RyGachaPool::where('is_active', true)->get();
        return $this->success(['pools' => $pools]);
    }

    /**
     * 11. 独立购买月票接口 (优先余额支付，次选易支付直连)
     */
    public function buyMonthlyPass(Request $request)
    {
        if ($error = $this->beforePluginAction()) return $error[1];

        $user = $request->user();
        $userId = $user->id;
        $priceYuan = (float)$this->getConfig('monthly_pass_price', 9.9);
        $priceCents = (int)round($priceYuan * 100); // 转为分

        $payType = $request->input('pay_type', 'balance'); // 'balance' 或 'epay'

        // ================= 方式 1：Xboard 账户余额直接结算 (最推荐，秒开通) =================
        if ($payType === 'balance') {
            return DB::transaction(function () use ($user, $userId, $priceCents, $priceYuan) {
                // 刷新锁定用户表
                $dbUser = \App\Models\User::where('id', $userId)->lockForUpdate()->first();
                if ($dbUser->balance < $priceCents) {
                    $shortage = ($priceCents - $dbUser->balance) / 100;
                    return $this->fail([400, "账户余额不足！月票需 ¥{$priceYuan}，当前余额仅 ¥" . ($dbUser->balance / 100) . "，还差 ¥{$shortage}。"]);
                }

                // 扣除余额
                $dbUser->balance -= $priceCents;
                $dbUser->save();

                // 发放月票
                $days = (int)$this->getConfig('monthly_pass_days', 30);
                $res = app(\Plugin\RhythmGacha\Services\MonthlyPassService::class)->grantPass($userId, $days, 'BALANCE_BUY');

                return $this->success([
                    'message'   => "🎉 余额支付成功！已开通 30 天 HoloPassport 月票！",
                    'expire_at' => $res['expire_at']
                ]);
            });
        }

        // ================= 方式 2：拉起 Xboard 配置的易支付收银台 =================
        if ($payType === 'epay') {
            // 读取 Xboard 后台启用的易支付配置
            $payment = \App\Models\Payment::where('payment', 'Epay')->where('enable', 1)->first();
            if (!$payment) {
                return $this->fail([400, "系统未配置或未启用易支付网关，请前往前台充值余额后使用余额购买！"]);
            }

            // 生成专属订单流水号 (带插件标识，不进 Xboard 原生套餐订单表)
            $outTradeNo = 'PASS_' . $userId . '_' . time();

            // 核心改造：读取后台自定义回调 Base URL，留空则自动回退到当前站点 url()
            $customBaseUrl = trim((string)$this->getConfig('callback_base_url', ''));
            $baseUrl = !empty($customBaseUrl) ? rtrim($customBaseUrl, '/') : rtrim(url('/'), '/');

            $notifyUrl = "{$baseUrl}/api/v1/rhythm-gacha/pass/epay/notify";
            $returnUrl = "{$baseUrl}/api/v1/rhythm-gacha/app";

            $epayConfig = $payment->config;
            $params = [
                'pid'          => $epayConfig['epay_pid'] ?? $epayConfig['pid'],
                'type'         => $request->input('epay_channel', 'alipay'), // alipay 或 wxpay
                'out_trade_no' => $outTradeNo,
                'notify_url'   => $notifyUrl,
                'return_url'   => $returnUrl,
                'name'         => 'HoloPassport 月票 (30天)',
                'money'        => sprintf('%.2f', $priceYuan),
            ];

            // 签名生成
            ksort($params);
            $signStr = urldecode(http_build_query($params)) . ($epayConfig['epay_key'] ?? $epayConfig['key']);
            $params['sign'] = md5($signStr);
            $params['sign_type'] = 'MD5';

            $epayUrl = rtrim($epayConfig['epay_url'] ?? $epayConfig['url'], '/') . '/submit.php?' . http_build_query($params);

            return $this->success([
                'pay_url' => $epayUrl,
                'trade_no' => $outTradeNo
            ]);
        }

        return $this->fail([400, "不支持的支付方式"]);
    }

    /**
     * 12. 易支付异步回调验证
     */
    public function handleEpayNotify(Request $request)
    {
        $data = $request->all();
        $payment = \App\Models\Payment::where('payment', 'Epay')->where('enable', 1)->first();
        if (!$payment) return response('fail', 400);

        $key = $payment->config['epay_key'] ?? $payment->config['key'];
        
        // 校验签名
        $sign = $data['sign'] ?? '';
        unset($data['sign'], $data['sign_type']);
        ksort($data);
        $checkSign = md5(urldecode(http_build_query($data)) . $key);

        if ($sign !== $checkSign) return response('fail', 400);

        if (($data['trade_status'] ?? '') === 'TRADE_SUCCESS') {
            $outTradeNo = $data['out_trade_no'];
            // 解析出用户ID: PASS_{userId}_{time}
            $parts = explode('_', $outTradeNo);
            $userId = (int)($parts[1] ?? 0);

            if ($userId > 0) {
                $days = (int)$this->getConfig('monthly_pass_days', 30);
                app(\Plugin\RhythmGacha\Services\MonthlyPassService::class)->grantPass($userId, $days, 'EPAY_ONLINE');
            }
        }

        return response('success');
    }
    /**
     * 13. 专属独立 Telegram 机器人 Webhook 接收端点
     */
    public function handleTelegramWebhook(Request $request, \Plugin\RhythmGacha\Services\TelegramBotService $botService)
    {
        $update = $request->all();
        $botService->handleUpdate($update);
        return response('OK');
    }

    /**
     * 14. 一键自动向 Telegram 官方注册 Webhook (浏览器点一下即可完成)
     */
    public function setupTelegramWebhook(\Plugin\RhythmGacha\Services\TelegramBotService $botService)
    {
        if ($error = $this->beforePluginAction()) return $error[1];

        $res = $botService->registerWebhook();
        if ($res['ok'] ?? false) {
            return $this->success([
                'message' => '🎉 Telegram 独立机器人 Webhook 注册成功！机器人已正式激活上线！',
                'telegram_response' => $res
            ]);
        }

        return $this->fail([400, "Webhook 注册失败: " . ($res['description'] ?? '未知错误')]);
    }
    /**
     * 15. hololive 截图全内存审核 (全量 Throwable 级安全捕获)
     */
    public function auditHololiveScreenshot(Request $request, \Plugin\RhythmGacha\Services\Game\HololiveVisionService $visionService)
    {
        if ($error = $this->beforePluginAction()) return $error[1];

        $file = $request->file('screenshot');
        if (!$file || !$file->isValid()) {
            return $this->fail([400, "请选择有效的截图文件上传！"]);
        }

        $binary = file_get_contents($file->getRealPath());

        try {
            $result = $visionService->auditAndSettle($request->user()->id, $binary);
            return $this->success([
                'message'      => "🎉 战绩核验通过！曲目《{$result['song_title']}》达成 {$result['rank']} 级，入账 {$result['gems_earned']} 💎 宝石！",
                'data'         => $result,
                'process_logs' => $result['process_logs'] ?? []
            ]);
        } catch (\Throwable $e) { // 核心修改：改用 \Throwable 拦截所有错误
            return response()->json([
                'status'       => 'fail',
                'message'      => $e->getMessage(),
                'process_logs' => $visionService->getLogs()
            ], 400);
        }
    }
    /**
     * 16. 渲染插件内置二次元活动主页面
     */
    public function renderApp()
    {
        $path = __DIR__ . '/../resources/views/app.html';
        if (!file_exists($path)) {
            return response("内置视图文件缺失，请确认已放置在 resources/views/app.html", 404);
        }
        return response(file_get_contents($path), 200, [
            'Content-Type' => 'text/html; charset=utf-8'
        ]);
    }

    /**
     * 17. 读取插件静态资源 (如 loading.webp、抽卡视频等)
     */
    public function renderAsset($filename)
    {
        $safeName = basename($filename);
        $path = __DIR__ . '/../resources/assets/' . $safeName;
        if (!file_exists($path)) {
            return response("Asset not found", 404);
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'webp' => 'image/webp',
            'png'  => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'mp4'  => 'video/mp4',
            default => 'application/octet-stream'
        };
        return response(file_get_contents($path), 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=86400'
        ]);
    }
    /**
     * 18. 生成落雪官网 OAuth 授权链接
     */
    public function getLxnsAuthorizeUrl(Request $request)
    {
        if ($error = $this->beforePluginAction()) return $error[1];

        $clientId = $this->getConfig('lxns_oauth_client_id');
        if (!$clientId) {
            return $this->fail([400, "管理员未配置落雪 lxns_oauth_client_id，请改用直接填写个人Token或好友码！"]);
        }

        $userId = $request->user()->id;
        $customBaseUrl = trim((string)$this->getConfig('callback_base_url', ''));
        $baseUrl = !empty($customBaseUrl) ? rtrim($customBaseUrl, '/') : rtrim(url('/'), '/');
        $redirectUri = "{$baseUrl}/api/v1/rhythm-gacha/oauth/lxns/callback";

        $params = http_build_query([
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => 'read_user read_scores',
            'state'         => base64_encode(json_encode(['user_id' => $userId]))
        ]);

        return $this->success([
            'url' => "https://maimai.lxns.net/oauth/authorize?{$params}"
        ]);
    }

    /**
     * 19. 落雪 OAuth 官网授权回调处理
     */
    public function handleLxnsCallback(Request $request)
    {
        $code = $request->input('code');
        $state = json_decode(base64_decode($request->input('state', '')), true);
        $userId = $state['user_id'] ?? null;

        if (!$code || !$userId) {
            return response("落雪授权参数失效，请重新发起绑定", 400);
        }

        try {
            $clientId = $this->getConfig('lxns_oauth_client_id');
            $clientSecret = $this->getConfig('lxns_oauth_client_secret');
            $customBaseUrl = trim((string)$this->getConfig('callback_base_url', ''));
            $baseUrl = !empty($customBaseUrl) ? rtrim($customBaseUrl, '/') : rtrim(url('/'), '/');
            $redirectUri = "{$baseUrl}/api/v1/rhythm-gacha/oauth/lxns/callback";

            // 1. 换取 Token
            $tokenRes = \Illuminate\Support\Facades\Http::asForm()->post('https://maimai.lxns.net/oauth/token', [
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'code'          => $code,
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => $redirectUri
            ]);

            if (!$tokenRes->successful()) {
                return response("换取落雪 Token 失败: " . $tokenRes->body(), 400);
            }

            $userToken = $tokenRes->json('access_token');

            // 2. 获取玩家资料
            $driver = new \Plugin\RhythmGacha\Services\Game\MaimaiDriver();
            $profile = $driver->verifyAndGetProfile($userToken);

            // 3. 永久绑定个人 Token 入库
            $ryUser = \Plugin\RhythmGacha\Models\RyUser::firstOrCreate(['user_id' => $userId]);
            $ryUser->maimai_id = $userToken;
            $ryUser->save();

            return response("
                <!DOCTYPE html><html><head><meta charset='UTF-8'></head>
                <body style='background:#0f172a;color:#fff;font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;'>
                    <div style='text-align:center;'>
                        <h2 style='color:#38bdf8;'>🎉 落雪查分器授权成功！</h2>
                        <p style='color:#94a3b8;'>已关联玩家: <strong>{$profile['nickname']}</strong> (Rating: {$profile['rating']})</p>
                        <script>
                            if (window.opener) {
                                window.opener.postMessage({ type: 'MAIMAI_AUTH_SUCCESS', nickname: '{$profile['nickname']}', rating: '{$profile['rating']}' }, '*');
                                setTimeout(() => window.close(), 1200);
                            } else {
                                setTimeout(() => window.location.href = '/api/v1/rhythm-gacha/app', 1500);
                            }
                        </script>
                    </div>
                </body></html>
            ");
        } catch (\Exception $e) {
            return response("落雪绑定异常: " . $e->getMessage(), 500);
        }
    }
}