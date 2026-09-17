<?php

namespace Plugin\RhythmGacha\Models;

use Illuminate\Database\Eloquent\Model;

class RyUser extends Model
{
    protected $table = 'ry_users';
    protected $primaryKey = 'user_id';
    public $incrementing = false; // 因为主键是 Xboard 的 user_id，不是自增的
    public $timestamps = false;   // 保持精简，不需要 created_at

    protected $guarded = []; // 允许所有字段批量赋值

    // 自动转换时间类型和布尔值
    protected $casts = [
        'last_stamina_update' => 'datetime',
        'monthly_pass_expire' => 'datetime',
        'is_admin' => 'boolean',
        'is_next_up_guaranteed' => 'boolean',
    ];
}