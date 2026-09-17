<?php

namespace Plugin\RhythmGacha\Models;

use Illuminate\Database\Eloquent\Model;

class RyOsuScore extends Model
{
    protected $table = 'ry_osu_scores';
    public $timestamps = true;
    protected $guarded = [];

    protected $casts = [
        'played_at' => 'datetime',
        'accuracy'  => 'float',
        'difficulty_rating' => 'float',
    ];
}