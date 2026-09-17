<?php

namespace Plugin\RhythmGacha\Models;

use Illuminate\Database\Eloquent\Model;

class RyHololiveChart extends Model
{
    protected $table = 'ry_hololive_charts';
    public $timestamps = true;
    protected $guarded = [];

    protected $casts = [
        'total_notes'    => 'integer',
        'verified_count' => 'integer',
    ];
}