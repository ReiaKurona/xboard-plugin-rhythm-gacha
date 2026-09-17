<?php

namespace Plugin\RhythmGacha\Events;

use Illuminate\Queue\SerializesModels;

class ItemRedeemed
{
    use SerializesModels;

    public $userId;
    public $type;
    public $amount;

    public function __construct(int $userId, string $type, int $amount)
    {
        $this->userId = $userId;
        $this->type = $type;    // 如: 'traffic', 'stamina_card', 'cps_coupon'
        $this->amount = $amount; // 具体的 MB 值 或 天数
    }
}