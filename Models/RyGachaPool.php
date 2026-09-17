<?php

namespace Plugin\RhythmGacha\Models;

use Illuminate\Database\Eloquent\Model;

class RyGachaPool extends Model
{
    protected $table = 'ry_gacha_pools';
    public $timestamps = true;
    protected $guarded = [];

    // 自动将 JSON 转换为 PHP 数组
    protected $casts = [
        'up_5_star_ids' => 'array',
        'up_4_star_ids' => 'array',
        'is_active'     => 'boolean',
    ];
}