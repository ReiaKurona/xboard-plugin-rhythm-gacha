<?php

namespace Plugin\RhythmGacha\Models;

use Illuminate\Database\Eloquent\Model;

class RyMaimaiScore extends Model
{
    protected $table = 'ry_maimai_scores';
    public $timestamps = true;
    protected $guarded = [];

    protected $casts = [
        'ds' => 'float',
        'achievements' => 'float',
        'dx_score' => 'integer',
    ];
}