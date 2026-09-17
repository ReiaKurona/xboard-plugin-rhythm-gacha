<?php

namespace Plugin\RhythmGacha\Listeners;

use Plugin\RhythmGacha\Events\ItemRedeemed;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class DeliverXboardItem
{
    public function handle(ItemRedeemed $event)
    {
        $user = User::find($event->userId);
        if (!$user) return;

        switch ($event->type) {
            case 'traffic':
                // 确保入参必须是纯整数 MB！
                $amountMb = (int)$event->amount;

                // 核心换算：1 MB = 1024 * 1024 字节 (Bytes)
                $bytesToAdd = $amountMb * 1048576; // 1048576 = 1024 * 1024

                $beforeGB = $user->transfer_enable / 1073741824;
                $user->transfer_enable += $bytesToAdd;
                $user->save();
                $afterGB = $user->transfer_enable / 1073741824;

                Log::info("RhythmGacha流量到账: 用户[{$event->userId}] +{$amountMb}MB (+{$bytesToAdd} 字节), Xboard额度: {$beforeGB}GB -> {$afterGB}GB");
                break;
                
            case 'stamina_card':
                $ryUser = \Plugin\RhythmGacha\Models\RyUser::where('user_id', $event->userId)->first();
                if ($ryUser) {
                    app(\Plugin\RhythmGacha\Services\StaminaService::class)->addStamina($ryUser, (int)$event->amount);
                }
                break;

            case 'monthly_pass':
                $ryUser = \Plugin\RhythmGacha\Models\RyUser::where('user_id', $event->userId)->first();
                if ($ryUser) {
                    $currentExpire = $ryUser->monthly_pass_expire ? strtotime($ryUser->monthly_pass_expire) : time();
                    $newExpire = max($currentExpire, time()) + ((int)$event->amount * 86400);
                    $ryUser->monthly_pass_expire = date('Y-m-d H:i:s', $newExpire);
                    $ryUser->save();
                }
                break;

                //cps
        }
    }
}