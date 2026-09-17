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

    /**
     * 多途径拉取成绩 (支持个人 API Token 或 好友码+DevToken)
     */
    private function fetchScoresFromLxns(string $identifier, string $devToken): array
    {
        // 途径 A：输入的是个人 API 密钥 (X-User-Token)
        if (strlen($identifier) >= 30 && !is_numeric($identifier)) {
            $res = Http::timeout(10)->withHeaders([
                'X-User-Token' => $identifier,
                'Accept'       => 'application/json'
            ])->get("{$this->baseUrl}/user/maimai/player/scores");

            if ($res->successful()) {
                return $res->json('data') ?? [];
            }
            Log::warning("落雪个人API查询失败: " . $res->body());
        }

        // 途径 B：输入的是好友码 (15位数字) 或 QQ号，且后台配置了 Developer Token
        if (!empty($devToken)) {
            // 如果是纯数字
            $endpoint = is_numeric($identifier) && strlen($identifier) <= 11 
                ? "{$this->baseUrl}/maimai/player/qq/{$identifier}" 
                : "{$this->baseUrl}/maimai/player/{$identifier}/recents";

            // 如果查的是 QQ，先查出好友码
            if (str_contains($endpoint, '/qq/')) {
                $pRes = Http::timeout(8)->withHeaders(['Authorization' => $devToken])->get($endpoint);
                if ($pRes->successful() && $pRes->json('data.friend_code')) {
                    $friendCode = $pRes->json('data.friend_code');
                    $endpoint = "{$this->baseUrl}/maimai/player/{$friendCode}/recents";
                }
            }

            $res = Http::timeout(10)->withHeaders([
                'Authorization' => $devToken,
                'Accept'        => 'application/json'
            ])->get($endpoint);

            if ($res->successful()) {
                return $res->json('data') ?? [];
            }
            Log::warning("落雪开发者API查询失败: " . $res->body());
        }

        return [];
    }

    /**
     * 绑定前验明正身 (携带合法浏览器 UA，并透传落雪真实错误)
     */
    public function verifyAndGetProfile(string $identifier): array
    {
        $plugin = app(\App\Services\Plugin\PluginManager::class)->getEnabledPlugins()['rhythm_gacha'] ?? null;
        $devToken = $plugin ? trim((string)$plugin->getConfig('lxns_developer_token', '')) : '';

        // 统一浏览器请求头，防止被 Cloudflare WAF 阻断
        $headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
            'Accept'     => 'application/json'
        ];

        // 途径 A：个人 API Token (X-User-Token)
        if (strlen($identifier) >= 30 && !is_numeric($identifier)) {
            $headers['X-User-Token'] = $identifier;
            $res = Http::timeout(10)->withHeaders($headers)->get("{$this->baseUrl}/user/maimai/player");

            if ($res->successful()) {
                $d = $res->json('data') ?? [];
                return [
                    'nickname'    => $d['name'] ?? '理论值',
                    'rating'      => $d['rating'] ?? 0,
                    'friend_code' => $d['friend_code'] ?? 0
                ];
            }

            // 核心排错：透传落雪官方真实报错！
            $status = $res->status();
            $msg = $res->json('message') ?? $res->body();

            if ($status === 404 || str_contains($msg, 'not found')) {
                throw new Exception("落雪查分器未找到您的玩家档案 (404)！请先在落雪网页端导入一次舞萌战绩！");
            }
            if ($status === 401) {
                throw new Exception("落雪 Token 鉴权失败 (401)！请确认 Token 是否填写完整或重新生成。");
            }
            throw new Exception("落雪接口异常 [{$status}]: {$msg}");
        }

        // 途径 B：好友码 / QQ
        if (!empty($devToken)) {
            $headers['Authorization'] = $devToken;
            $endpoint = (is_numeric($identifier) && strlen($identifier) <= 11)
                ? "{$this->baseUrl}/maimai/player/qq/{$identifier}"
                : "{$this->baseUrl}/maimai/player/{$identifier}";

            $res = Http::timeout(10)->withHeaders($headers)->get($endpoint);

            if ($res->successful()) {
                $d = $res->json('data') ?? [];
                return [
                    'nickname'    => $d['name'] ?? '理论值',
                    'rating'      => $d['rating'] ?? 0,
                    'friend_code' => $d['friend_code'] ?? 0
                ];
            }
            $msg = $res->json('message') ?? "HTTP " . $res->status();
            throw new Exception("落雪开发者接口返回: {$msg}");
        }

        throw new Exception("未检测到有效个人 Token。若使用好友码，请提醒管理员在后台配置 Developer Token！");
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