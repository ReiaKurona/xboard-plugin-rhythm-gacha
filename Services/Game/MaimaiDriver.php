<?php

namespace Plugin\RhythmGacha\Services\Game;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Plugin\RhythmGacha\Models\RyMaimaiScore;

class MaimaiDriver implements GameDriverInterface
{
    private string $baseUrl = 'https://maimai.lxns.net/api/v0';
    private string $assetCdn = 'https://assets2.lxns.net/maimai/jacket';

    public function fetchLatestScore(string $uid): ?array
    {
        $res = $this->syncUserRecords(0, $uid, 1);
        return $res['settled'][0] ?? null;
    }

    /**
     * 落雪批量查重与结算引擎 (24小时时效 + 体力联动 + 0体力防囤积)
     * @param int $userId 用户 ID
     * @param string $identifier 个人 API Token、好友码 (15位数字)、或 QQ号
     */
    public function syncUserRecords(int $userId, string $identifier, int $availableStamina, array $rewardMap = []): array
    {
        $plugin = app(\App\Services\Plugin\PluginManager::class)->getEnabledPlugins()['rhythm_gacha'] ?? null;
        $devToken = $plugin ? trim((string)$plugin->getConfig('lxns_developer_token', '')) : '';

        // 1. 获取玩家近期游玩记录 (Recent 50)
        $scores = $this->fetchScoresFromLxns($identifier, $devToken);
        if (empty($scores)) {
            return ['settled' => [], 'consumed_stamina' => 0, 'invalid_count' => 0];
        }

        $settled = [];
        $consumedStamina = 0;
        $invalidCount = 0;
        $now = time();
        $twentyFourHoursAgo = $now - 86400; // 24小时阈值

        foreach ($scores as $item) {
            // 提取准确时间戳 (优先 play_time，兜底 upload_time)
            $timeStr = $item['play_time'] ?? $item['upload_time'] ?? null;
            $playedTime = $timeStr ? strtotime($timeStr) : $now;

            // 【规则 1：24 小时硬校验】
            if ($playedTime < $twentyFourHoursAgo) {
                continue;
            }

            $songId = (int)($item['id'] ?? 0);
            $songType = $item['type'] ?? 'dx';
            $levelIndex = (int)($item['level_index'] ?? 3);
            $achievements = (float)($item['achievements'] ?? 0.0);
            $dxScore = (int)($item['dx_score'] ?? 0);

            // 过滤无效空成绩
            if ($achievements <= 0.0) continue;

            // 【规则 2：查重防伪指纹】
            $scoreId = md5("lxns_{$songId}_{$songType}_{$levelIndex}_{$achievements}_{$dxScore}_{$timeStr}");

            if (RyMaimaiScore::where('user_id', $userId)->where('score_id', $scoreId)->exists()) {
                continue;
            }

            $level = $this->calculateLevel($achievements, $item['rate'] ?? '');

            // 【规则 3：体力判定与占位锁死】
            $hasStamina = ($consumedStamina < $availableStamina);
            $gems = $hasStamina ? ($rewardMap["L{$level}"] ?? 10) : 0;

            if ($hasStamina) {
                $consumedStamina++;
            } else {
                $invalidCount++;
            }

            // 谱面难度标签对应
            $labels = [0 => 'Basic', 1 => 'Advanced', 2 => 'Expert', 3 => 'Master', 4 => 'Re:MASTER'];
            $levelLabel = $labels[$levelIndex] ?? ($item['level'] ?? 'Master');

            // 官方曲绘 CDN (如果曲目 ID 大于 10000 且非宴会场，按官方规范取余处理)
            $jacketSongId = $songId > 10000 && $songId < 100000 ? ($songId % 10000) : $songId;
            $coverUrl = "{$this->assetCdn}/{$jacketSongId}.png";

            $settled[] = [
                'user_id'       => $userId,
                'score_id'      => $scoreId,
                'song_id'       => $songId,
                'song_title'    => $item['song_name'] ?? "曲目 {$songId}",
                'cover_url'     => $coverUrl,
                'song_type'     => strtoupper($songType),
                'level_label'   => $levelLabel,
                'level_index'   => $levelIndex,
                'ds'            => (float)($item['level_value'] ?? 0.0),
                'achievements'  => $achievements,
                'dx_score'      => $dxScore,
                'rate'          => strtoupper($item['rate'] ?? 'D'),
                'fc'            => $item['fc'] ?? '',
                'fs'            => $item['fs'] ?? '',
                'level_awarded' => $level,
                'gems_awarded'  => $gems,
                'created_at'    => date('Y-m-d H:i:s', $playedTime)
            ];
        }

        return [
            'settled'          => $settled,
            'consumed_stamina' => $consumedStamina,
            'invalid_count'    => $invalidCount
        ];
    }

    private function fetchScoresFromLxns(string $identifier, string $devToken): array
    {
        $baseHeaders = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
            'Accept'     => 'application/json'
        ];

        // 途径 A：Token 查询 (兼容 OAuth Bearer 与 个人 X-User-Token)
        if (strlen($identifier) >= 20 && !is_numeric($identifier)) {
            $pureToken = preg_replace('/^Bearer\s+/i', '', $identifier);

            // 先试 OAuth Bearer 方式
            $res = Http::timeout(10)->withHeaders(array_merge($baseHeaders, [
                'Authorization' => 'Bearer ' . $pureToken
            ]))->get("{$this->baseUrl}/user/maimai/player/scores");

            if ($res->successful()) {
                return $res->json('data') ?? [];
            }

            // 再试 X-User-Token 方式
            $res = Http::timeout(10)->withHeaders(array_merge($baseHeaders, [
                'X-User-Token' => $pureToken
            ]))->get("{$this->baseUrl}/user/maimai/player/scores");

            if ($res->successful()) {
                return $res->json('data') ?? [];
            }
            Log::warning("落雪获取成绩失败", ['status' => $res->status(), 'body' => $res->body()]);
        }

        // 途径 B：开发者好友码查询
        if (!empty($devToken)) {
            $endpoint = (is_numeric($identifier) && strlen($identifier) <= 11)
                ? "{$this->baseUrl}/maimai/player/qq/{$identifier}" 
                : "{$this->baseUrl}/maimai/player/{$identifier}/recents";

            if (str_contains($endpoint, '/qq/')) {
                $pRes = Http::timeout(8)->withHeaders(array_merge($baseHeaders, ['Authorization' => $devToken]))->get($endpoint);
                if ($pRes->successful() && $pRes->json('data.friend_code')) {
                    $friendCode = $pRes->json('data.friend_code');
                    $endpoint = "{$this->baseUrl}/maimai/player/{$friendCode}/recents";
                }
            }

            $res = Http::timeout(10)->withHeaders(array_merge($baseHeaders, [
                'Authorization' => $devToken
            ]))->get($endpoint);

            if ($res->successful()) {
                return $res->json('data') ?? [];
            }
        }

        return [];
    }

    /**
     * 绑定前验明正身 (多端点自动探测与原版错误透传)
     */
    public function verifyAndGetProfile(string $identifier): array
    {
        $plugin = app(\App\Services\Plugin\PluginManager::class)->getEnabledPlugins()['rhythm_gacha'] ?? null;
        $devToken = $plugin ? trim((string)$plugin->getConfig('lxns_developer_token', '')) : '';

        $baseHeaders = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
            'Accept'     => 'application/json'
        ];

        // 途径 A：长字符串 Token (兼容 OAuth Access Token 与 个人 X-User-Token)
        if (strlen($identifier) >= 20 && !is_numeric($identifier)) {
            $pureToken = trim(preg_replace('/^Bearer\s+/i', '', $identifier));

            // 端点探测列表 (优先尝试玩家接口，兜底尝试基础用户接口)
            $endpoints = [
                "{$this->baseUrl}/user/maimai/player",
                "{$this->baseUrl}/user/profile",
                "{$this->baseUrl}/user/info",
                "{$this->baseUrl}/user"
            ];

            $lastError = '';
            $lastStatus = 401;

            // 1. 尝试以 OAuth Bearer Token 方式请求各端点
            foreach ($endpoints as $url) {
                $res = Http::timeout(8)->withHeaders(array_merge($baseHeaders, [
                    'Authorization' => 'Bearer ' . $pureToken
                ]))->get($url);

                if ($res->successful()) {
                    $d = $res->json('data') ?? $res->json() ?? [];
                    return [
                        'nickname'    => $d['name'] ?? $d['nickname'] ?? $d['username'] ?? '理论值',
                        'rating'      => $d['rating'] ?? 0,
                        'friend_code' => $d['friend_code'] ?? 0
                    ];
                }

                $lastStatus = $res->status();
                $lastError = $res->body();
                \Log::warning("落雪OAuth探测端点 [{$url}] 失败: {$lastStatus} - {$lastError}");
            }

            // 2. 如果 Bearer 失败，尝试作为个人 API 密钥 (X-User-Token) 访问
            $pRes = Http::timeout(8)->withHeaders(array_merge($baseHeaders, [
                'X-User-Token' => $pureToken
            ]))->get("{$this->baseUrl}/user/maimai/player");

            if ($pRes->successful()) {
                $d = $pRes->json('data') ?? [];
                return [
                    'nickname'    => $d['name'] ?? '理论值',
                    'rating'      => $d['rating'] ?? 0,
                    'friend_code' => $d['friend_code'] ?? 0
                ];
            }

            // 核心透出：把落雪返回的原版报错直接抛给前端查看！
            throw new Exception("落雪服务器返回 [HTTP {$lastStatus}]: " . $lastError);
        }

        // 途径 B：好友码 / QQ
        if (!empty($devToken)) {
            $headers = array_merge($baseHeaders, ['Authorization' => $devToken]);
            $endpoint = (is_numeric($identifier) && strlen($identifier) <= 11)
                ? "{$this->baseUrl}/maimai/player/qq/{$identifier}"
                : "{$this->baseUrl}/maimai/player/{$identifier}";

            $res = Http::timeout(8)->withHeaders($headers)->get($endpoint);

            if ($res->successful()) {
                $d = $res->json('data') ?? [];
                return [
                    'nickname'    => $d['name'] ?? '理论值',
                    'rating'      => $d['rating'] ?? 0,
                    'friend_code' => $d['friend_code'] ?? 0
                ];
            }
            throw new Exception("落雪开发者接口返回: " . $res->body());
        }

        throw new Exception("未检测到有效 Token。若使用好友码，请确保后台已配置落雪 Developer Token！");
    }

    private function calculateLevel(float $achievements, string $rateStr): int
    {
        if ($achievements >= 99.0000) return 5; // SS及以上 (SS, SS+, SSS, SSS+)
        if ($achievements >= 97.0000) return 4; // S, S+
        if ($achievements >= 80.0000) return 3; // A, AA, AAA
        if ($achievements >= 60.0000) return 2; // B
        return 1; // C, D
    }
}