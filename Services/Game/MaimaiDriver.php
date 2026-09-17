<?php

namespace Plugin\RhythmGacha\Services\Game;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Plugin\RhythmGacha\Models\RyMaimaiScore;

class MaimaiDriver implements GameDriverInterface
{
    private string $baseUrl = 'https://www.diving-fish.com/api';
    protected string $gameCategory = 'maimaidxprober';

    public function fetchLatestScore(string $uid): ?array
    {
        $res = $this->syncUserRecords(0, $uid, 1);
        return $res['settled'][0] ?? null;
    }

    /**
     * 舞萌批量查重与结算引擎 (体力联动)
     */
    public function syncUserRecords(int $userId, string $identifier, int $availableStamina, array $rewardMap = []): array
    {
        try {
            $records = [];
            // 自动判断是 Token 还是 普通用户名/QQ
            if (strlen($identifier) >= 30 && !is_numeric($identifier)) {
                $records = $this->fetchByImportToken($identifier);
            } else {
                $records = $this->fetchByPublicQuery($identifier);
            }

            if (empty($records)) {
                return ['settled' => [], 'consumed_stamina' => 0];
            }

            $settled = [];
            $consumedStamina = 0;
            $invalidCount = 0;

            foreach ($records as $item) {
                $achievements = (float)($item['achievements'] ?? 0);
                if ($achievements <= 0.0) continue;

                $songId = $item['song_id'] ?? $item['mid'] ?? 0;
                $levelIndex = $item['level_index'] ?? 0;
                $dxScore = $item['dxScore'] ?? $item['score'] ?? 0;

                $scoreId = md5("mai_{$songId}_{$levelIndex}_{$achievements}_{$dxScore}");

                // 查重已存在的跳过
                if (RyMaimaiScore::where('user_id', $userId)->where('score_id', $scoreId)->exists()) {
                    continue;
                }

                $level = $this->calculateLevel($achievements, $item['rate'] ?? '');

                // 体力判定与占位锁死
                $hasStamina = ($consumedStamina < $availableStamina);
                $gems = $hasStamina ? ($rewardMap["L{$level}"] ?? 10) : 0;

                if ($hasStamina) {
                    $consumedStamina++;
                } else {
                    $invalidCount++;
                }

                $settled[] = [
                    'user_id'       => $userId,
                    'score_id'      => $scoreId,
                    'song_id'       => (int)$songId,
                    'song_title'    => $item['title'] ?? '未知曲目',
                    'song_type'     => $item['type'] ?? 'DX',
                    'level_label'   => $item['level_label'] ?? 'Master',
                    'level_index'   => (int)$levelIndex,
                    'ds'            => (float)($item['ds'] ?? 0.0),
                    'achievements'  => $achievements,
                    'dx_score'      => (int)$dxScore,
                    'rate'          => strtoupper($item['rate'] ?? 'D'),
                    'fc'            => $item['fc'] ?? '',
                    'fs'            => $item['fs'] ?? '',
                    'level_awarded' => $level,
                    'gems_awarded'  => $gems, // 没体力时强制记为 0 钻！
                ];
            }

            return [
                'settled' => $settled,
                'consumed_stamina' => $consumedStamina,
                'invalid_count' => $invalidCount
            ];

        } catch (\Exception $e) {
            Log::error('舞萌查分器同步异常: ' . $e->getMessage());
            throw $e;
        }
    }

    private function fetchByPublicQuery(string $userOrQq): array
    {
        $payload = is_numeric($userOrQq) 
            ? ['qq' => $userOrQq, 'b50' => '1'] 
            : ['username' => $userOrQq, 'b50' => '1'];

        $response = Http::timeout(10)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post("{$this->baseUrl}/{$this->gameCategory}/query/player", $payload);

        if ($response->successful()) {
            $data = $response->json();
            if (isset($data['records'])) return $data['records'];
            if (isset($data['charts']['dx']) || isset($data['charts']['sd'])) {
                return array_merge($data['charts']['dx'] ?? [], $data['charts']['sd'] ?? []);
            }
        }
        return [];
    }

    private function fetchByImportToken(string $importToken): array
    {
        $response = Http::timeout(10)
            ->withHeaders(['Import-Token' => $importToken])
            ->get("{$this->baseUrl}/{$this->gameCategory}/player/records");

        return $response->successful() ? ($response->json('records') ?? []) : [];
    }

    private function calculateLevel(float $achievements, string $rateStr): int
    {
        if ($achievements >= 99.0000) return 5; // SS及以上
        if ($achievements >= 97.0000) return 4; // S, S+
        if ($achievements >= 80.0000) return 3; // A, AA, AAA
        if ($achievements >= 60.0000) return 2; // B
        return 1; // C, D
    }

    /**
     * 人性化核心：绑定前先验明正身，提取玩家名片与战力 (DX Rating)
     */
    public function verifyAndGetProfile(string $identifier): array
    {
        // 1. 如果是 Import-Token 查询
        if (strlen($identifier) >= 30 && !is_numeric($identifier)) {
            $res = Http::timeout(8)->withHeaders(['Import-Token' => $identifier])
                ->get("{$this->baseUrl}/{$this->gameCategory}/player/records");
            
            if ($res->status() === 400) {
                throw new \Exception("Import-Token 无效或已过期，请在水鱼主页重新生成！");
            }
            if ($res->successful()) {
                $d = $res->json();
                return [
                    'nickname' => $d['nickname'] ?? '神秘玩家',
                    'rating'   => $d['rating'] ?? 0,
                    'plate'    => $d['plate'] ?? ''
                ];
            }
        }

        // 2. 如果是 用户名 / QQ 公开查询
        $payload = is_numeric($identifier) ? ['qq' => $identifier, 'b50' => '1'] : ['username' => $identifier, 'b50' => '1'];
        $res = Http::timeout(8)->withHeaders(['Content-Type' => 'application/json'])
            ->post("{$this->baseUrl}/{$this->gameCategory}/query/player", $payload);

        if ($res->status() === 403) {
            throw new \Exception("该账号在水鱼开启了隐私保护！请改用水鱼个人主页的【Import-Token】进行绑定！");
        }
        if ($res->status() === 400 || !$res->successful()) {
            throw new \Exception("水鱼查分器未检索到该用户，请检查用户名或QQ号是否正确！");
        }

        $d = $res->json();
        return [
            'nickname' => $d['nickname'] ?? '神秘玩家',
            'rating'   => $d['rating'] ?? 0,
            'plate'    => $d['plate'] ?? ''
        ];
    }
}