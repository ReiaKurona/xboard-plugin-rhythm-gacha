<?php

namespace Plugin\RhythmGacha\Models;

use Illuminate\Database\Eloquent\Model;

class RyItem extends Model
{
    protected $table = 'ry_items';
    public $timestamps = false;
    protected $guarded = [];

    protected $casts = [
        'is_in_standard_pool' => 'boolean',
        'is_in_up_pool' => 'boolean',
    ];
}