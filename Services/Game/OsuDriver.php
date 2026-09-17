<?php

namespace Plugin\RhythmGacha\Services\Game;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
// 核心修复：引入专属的 osu! 模型，绝不再调用已废弃的 RyScoreLog
use Plugin\RhythmGacha\Models\RyOsuScore;

class OsuDriver implements GameDriverInterface
{
    private string $baseUrl = 'https://osu.ppy.sh/api/v2';
    private string $tokenUrl = 'https://osu.ppy.sh/oauth/token';

    public function fetchLatestScore(string $uid): ?array
    {
        $batch = $this->syncUserRecentScores(0, $uid, 1);
        return $batch['settled'][0] ?? null;
    }

    /**
     * 高级批量同步引擎：自动将用户名转为数字UID、过滤 >24h、排重
     */
    public function syncUserRecentScores(int $userId, string $osuUser, int $availableStamina, array $rewardMap = []): array
    {
        $token = $this->getAccessToken();
        if (!$token) {
            throw new \Exception("无法获取 osu! API 凭证，请检查 Xboard 后台 Client ID 与 Secret 配置是否正确！");
        }

        // 1. 智能将用户名解析为纯数字 UID (避免 404)
        $numericUserId = $osuUser;
        if (!is_numeric($osuUser)) {
            $userLookup = Http::timeout(10)
                ->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
                ->get("{$this->baseUrl}/users/" . urlencode($osuUser) . "?key=username");

            if (!$userLookup->successful()) {
                throw new \Exception("osu! 官方找不到用户名为 '{$osuUser}' 的玩家，请检查拼写！");
            }
            $numericUserId = $userLookup->json('id');
        }

        // 2. 使用官方纯数字 UID 获取最近成绩
        $response = Http::timeout(10)
            ->withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json'
            ])
            ->get("{$this->baseUrl}/users/{$numericUserId}/scores/recent", [
                'include_fails' => 0,
                'mode' => 'osu',
                'limit' => 20
            ]);

        if (!$response->successful()) {
            $err = $response->json('error') ?? $response->body();
            Log::error("osu! API 响应异常: " . $err);
            throw new \Exception("osu! API 请求失败: " . $err);
        }

        $scores = $response->json();
        if (empty($scores)) {
            return ['settled' => [], 'consumed_stamina' => 0];
        }

        $settled = [];
        $consumedStamina = 0;
        $invalidCount = 0; // 统计因体力不足未发钻的曲目数
        $now = time();
        $twentyFourHoursAgo = $now - 86400;

        foreach ($scores as $item) {
            $scoreId = (string) $item['id'];
            $playedTime = strtotime($item['created_at']);

            // 【规则 1：24小时限制】超过24小时的旧歌直接忽略
            if ($playedTime < $twentyFourHoursAgo) {
                continue;
            }

            // 【规则 2：查重】已记录过的通通跳过
            $exists = RyOsuScore::where('score_id', $scoreId)->exists();
            if ($exists) {
                continue;
            }

            $level = $this->normalizeRank($item['rank'] ?? 'D');

            // 【核心逻辑：动态体力锁】
            $hasStamina = ($consumedStamina < $availableStamina);
            $gems = $hasStamina ? ($rewardMap["L{$level}"] ?? 10) : 0;

            if ($hasStamina) {
                $consumedStamina++;
            } else {
                $invalidCount++;
            }

            // ==========================================
            // 【核心修复区域：全方位捕获 Lazer 与 Stable 分数】
            // 适配最新 osu! API v2 字段变更，优先抓取最大的合法分数
            // ==========================================
            $actualScore = 0;
            $scoreKeys = [
                'total_score',          // 最新 API 存放 Lazer 标准分数(1,000,000)的字段
                'legacy_total_score',   // 最新 API 存放 Stable 经典大数字分数的字段
                
                'classic_score',        // 旧版 API 兼容字段
                'classic_total_score',  // 旧版 API 兼容字段
                'score'                 // 原始字段（现常为空或0）
            ];
            
            foreach ($scoreKeys as $key) {
                if (isset($item[$key]) && is_numeric($item[$key])) {
                    $val = (int) $item[$key];
                    if ($val > $actualScore) {
                        $actualScore = $val;
                    }
                }
            }
            // ==========================================

            // 准确率安全转换
            $actualAcc = round((float) ($item['accuracy'] ?? 0.0), 4);

            // 提取封面与官方谱面链接
            $coverUrl = $item['beatmapset']['covers']['card'] 
                     ?? $item['beatmapset']['covers']['cover'] 
                     ?? '';
            $beatmapUrl = $item['beatmap']['url'] ?? "https://osu.ppy.sh/b/" . ($item['beatmap']['id'] ?? '');

            // 提取 300/100/50/miss 判定数
            $stats = $item['statistics'] ?? [];
            $c300 = (int)($stats['count_300'] ?? 0);
            $c100 = (int)($stats['count_100'] ?? 0);
            $c50  = (int)($stats['count_50'] ?? 0);
            $cMiss = (int)($stats['count_miss'] ?? 0);

            $pp = round((float)($item['pp'] ?? 0.0), 2);
            $actualScore = (int)($item['classic_score'] ?? $item['total_score'] ?? $item['score'] ?? 0);

            $recordData = [
                'user_id'           => $userId,
                'score_id'          => $scoreId,
                'beatmap_id'        => $item['beatmap']['id'] ?? null,
                'song_title'        => $item['beatmapset']['title_unicode'] ?? $item['beatmapset']['title'] ?? '未知曲目',
                'artist'            => $item['beatmapset']['artist_unicode'] ?? $item['beatmapset']['artist'] ?? '未知艺术家',
                'cover_url'         => $coverUrl,
                'beatmap_url'       => $beatmapUrl,
                'difficulty_rating' => (float)($item['beatmap']['difficulty_rating'] ?? 0.00),
                'score'             => $actualScore,
                'pp'                => $pp,
                'accuracy'          => round((float)($item['accuracy'] ?? 0), 4),
                'rank'              => strtoupper($item['rank'] ?? 'D'),
                'max_combo'         => (int)($item['max_combo'] ?? 0),
                'count_300'         => $c300,
                'count_100'         => $c100,
                'count_50'          => $c50,
                'count_miss'        => $cMiss,
                'level_awarded'     => $level,
                'gems_awarded'      => $gems,
                'played_at'         => date('Y-m-d H:i:s', $playedTime),
            ];

            $settled[] = $recordData;
        }

        return [
            'settled'          => $settled,
            'consumed_stamina' => $consumedStamina,
            'invalid_count'    => $invalidCount,
        ];
    }

    private function getAccessToken(): ?string
    {
        return Cache::remember('osu_api_v2_client_token', 86000, function () {
            $plugin = app(\App\Services\Plugin\PluginManager::class)->getEnabledPlugins()['rhythm_gacha'] ?? null;
            $clientId = $plugin?->getConfig('osu_api_client_id');
            $clientSecret = $plugin?->getConfig('osu_api_client_secret');

            if (!$clientId || !$clientSecret) {
                Log::error("RhythmGacha: 未配置 osu! Client ID 或 Secret！");
                return null;
            }

            $response = Http::asForm()->post($this->tokenUrl, [
                'client_id' => trim($clientId),
                'client_secret' => trim($clientSecret),
                'grant_type' => 'client_credentials',
                'scope' => 'public'
            ]);

            if ($response->successful()) {
                return $response->json('access_token');
            }

            Log::error("osu! Client Token 获取失败: " . $response->body());
            return null;
        });
    }

    private function normalizeRank(string $rank): int
    {
        $r = strtoupper($rank);
        if (in_array($r, ['X', 'XH', 'SS', 'SSH'])) return 5;
        if (in_array($r, ['S', 'SH'])) return 4;
        if ($r === 'A') return 3;
        if ($r === 'B') return 2;
        return 1;
    }
}