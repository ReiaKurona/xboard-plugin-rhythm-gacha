<?php

namespace Plugin\RhythmGacha\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Plugin\RhythmGacha\Models\RyItem;

class InstallCommand extends Command
{
    // 命名遵循 plugin-name:action 规范
    protected $signature = 'rhythm-gacha:install';
    protected $description = '初始化安装 RhythmGacha 插件数据表和道具字典';

    public function handle(): int
    {
        try {
            // 1. ry_users
            if (!Schema::hasTable('ry_users')) {
                Schema::create('ry_users', function (Blueprint $table) {
                    $table->integer('user_id')->primary();
                    $table->boolean('is_admin')->default(false);
                    $table->integer('gems')->default(0);
                    $table->integer('stamina')->default(10);
                    $table->timestamp('last_stamina_update')->nullable();
                    $table->timestamp('monthly_pass_expire')->nullable();
                    $table->integer('pity_standard')->default(0);
                    $table->integer('pity_up')->default(0);
                    $table->boolean('is_next_up_guaranteed')->default(false);
                    $table->string('osu_uid', 64)->nullable();
                    $table->string('maimai_id', 64)->nullable();
                    $table->string('last_osu_score_id', 64)->nullable();
                    $table->string('last_maimai_score_id', 64)->nullable();
                });
                $this->info("✅ ry_users 表创建成功");
            }

            // 2. ry_items
            if (!Schema::hasTable('ry_items')) {
                Schema::create('ry_items', function (Blueprint $table) {
                    $table->id();
                    $table->string('name', 64);
                    $table->integer('star_level');
                    $table->string('type', 32);
                    $table->integer('value_min');
                    $table->integer('value_max');
                    $table->boolean('is_in_standard_pool')->default(true);
                    $table->boolean('is_in_up_pool')->default(false);
                });
                $this->info("✅ ry_items 表创建成功");

                // 注入初始抽卡字典
                $items = [
                    ['name' => '【R】微型能量源', 'star_level' => 3, 'type' => 'traffic', 'value_min' => 1, 'value_max' => 10, 'is_in_standard_pool' => true, 'is_in_up_pool' => false],
                    ['name' => '【SR】超频数据核', 'star_level' => 4, 'type' => 'traffic', 'value_min' => 50, 'value_max' => 200, 'is_in_standard_pool' => true, 'is_in_up_pool' => false],
                    ['name' => '【SSR】量子跃迁矩阵', 'star_level' => 5, 'type' => 'traffic', 'value_min' => 1024, 'value_max' => 3072, 'is_in_standard_pool' => true, 'is_in_up_pool' => false],
                    ['name' => '【UR限定】5G虚空核心', 'star_level' => 5, 'type' => 'traffic', 'value_min' => 5120, 'value_max' => 5120, 'is_in_standard_pool' => false, 'is_in_up_pool' => true],
                ];
                foreach ($items as $item) {
                    RyItem::create($item);
                }
            }

            // 3. ry_backpack
            if (!Schema::hasTable('ry_backpack')) {
                Schema::create('ry_backpack', function (Blueprint $table) {
                    $table->id();
                    $table->integer('user_id')->index();
                    $table->integer('item_id');
                    $table->integer('amount')->default(1);
                    $table->boolean('is_used')->default(false);
                    $table->timestamp('used_at')->nullable();
                    $table->string('source', 32)->nullable();
                });
                $this->info("✅ ry_backpack 表创建成功");
            }

            $this->info("🎉 RhythmGacha 数据库构建完成！");
            return 0;
        } catch (\Exception $e) {
            $this->error("安装失败: " . $e->getMessage());
            return 1;
        }
    }
}