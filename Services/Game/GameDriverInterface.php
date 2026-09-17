<?php
namespace Plugin\RhythmGacha\Services\Game;

interface GameDriverInterface
{
    /**
     * 抓取最新战绩并标准化
     * @return array|null 返回格式: ['level' => 1~5, 'score_id' => '用于防刷防重的ID']
     */
    public function fetchLatestScore(string $uid): ?array;
}