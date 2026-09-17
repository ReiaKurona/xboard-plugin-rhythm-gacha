<?php

namespace Plugin\RhythmGacha\Models;

use Illuminate\Database\Eloquent\Model;

class RyHololiveScore extends Model
{
    protected $table = 'ry_hololive_scores';
    public $timestamps = true;
    protected $guarded = [];

    protected $casts = [
        'photo_time'    => 'datetime',
        'is_new_record' => 'boolean',
    ];
}