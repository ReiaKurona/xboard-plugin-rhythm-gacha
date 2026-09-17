<?php

namespace Plugin\RhythmGacha\Models;

use Illuminate\Database\Eloquent\Model;

class RyBackpack extends Model
{
    protected $table = 'ry_backpack';
    public $timestamps = false;
    protected $guarded = [];

    protected $casts = [
        'is_used' => 'boolean',
        'used_at' => 'datetime',
    ];

    // 关联道具字典，方便核销时查询道具属性
    public function item()
    {
        return $this->belongsTo(RyItem::class, 'item_id', 'id');
    }
}