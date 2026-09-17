<?php

use Illuminate\Support\Facades\Route;
use Plugin\RhythmGacha\Controllers\RhythmController;

// 1. 需要登录认证的用户前台接口 (带 user 中间件)
Route::group([
    'prefix' => 'api/v1/rhythm-gacha',
    'middleware' => 'user'
], function () {
    // 基础资产与体力
    Route::get('/info', [RhythmController::class, 'getInfo']);
    
    // 游戏账号绑定与同步
    Route::post('/bind', [RhythmController::class, 'bindAccount']);
    Route::post('/sync', [RhythmController::class, 'syncGameScore']);
    Route::get('/records', [RhythmController::class, 'getRecords']);
    Route::get('/oauth/osu/redirect', [RhythmController::class, 'getOsuAuthorizeUrl']);
    
    // 抽卡与背包核销
    Route::get('/pools', [RhythmController::class, 'getPools']);
    Route::post('/gacha/pull', [RhythmController::class, 'pullGacha']);
    Route::get('/backpack', [RhythmController::class, 'getBackpack']);
    Route::post('/backpack/redeem', [RhythmController::class, 'redeemItem']);
    
    // 月票购买与测试
    Route::post('/pass/buy', [RhythmController::class, 'buyMonthlyPass']);
    Route::post('/test/gems', [RhythmController::class, 'claimTestGems']);
    // hololive 截图内存上传审核
    Route::post('/hololive/audit', [RhythmController::class, 'auditHololiveScreenshot']);
});

// 2. 公开免登录的回调接口 (接收三方官网与支付网关通知)
Route::group([
    'prefix' => 'api/v1/rhythm-gacha'
], function () {
    // osu! 官网授权重定向跳转回调
    Route::get('/oauth/osu/callback', [RhythmController::class, 'handleOsuCallback']);
    
    // 核心修正：使用标准的 Route::any (接收易支付 GET / POST 异步通知)
    Route::any('/pass/epay/notify', [RhythmController::class, 'handleEpayNotify']);
    // 1. 独立机器人 Webhook 接收端点 (接收 Telegram 官方消息推送)
    Route::post('/telegram/webhook', [RhythmController::class, 'handleTelegramWebhook']);
    
    // 2. 一键自动向 Telegram 注册 Webhook 的快捷端点 (免去手动 curl)
    Route::get('/telegram/set-webhook', [RhythmController::class, 'setupTelegramWebhook']);
    // 核心新增：插件内置活动主页面渲染
    Route::get('/app', [RhythmController::class, 'renderApp']);
    // 静态素材流接口 (读取 loading.webp 等资产)
    Route::get('/asset/{filename}', [RhythmController::class, 'renderAsset']);

});