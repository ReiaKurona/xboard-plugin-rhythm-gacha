<?php

namespace Plugin\RhythmGacha\Services\Game;

use Exception;

class GameApiFactory
{
    public static function make(string $gameType): GameDriverInterface
    {
        return match (strtolower($gameType)) {
            'osu' => new OsuDriver(),
            'maimai' => new MaimaiDriver(),
            // 以后在这里加一行 'pjsk' => new PjskDriver(),
            default => throw new Exception("不支持的游戏类型: {$gameType}")
        };
    }
}