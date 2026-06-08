<?php

/**
 * Laravel Sanctum 个人访问令牌表。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id()->comment('主键');
            $table->string('tokenable_type')->comment('令牌所属模型类名');
            $table->unsignedBigInteger('tokenable_id')->comment('令牌所属模型主键');
            $table->index(['tokenable_type', 'tokenable_id']);
            $table->text('name')->comment('令牌名称');
            $table->string('token', 64)->unique()->comment('令牌 hash，唯一');
            $table->text('abilities')->nullable()->comment('授权能力列表 JSON');
            $table->timestamp('last_used_at')->nullable()->comment('最近使用时间');
            $table->timestamp('expires_at')->nullable()->index()->comment('过期时间');
            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('更新时间');

            $table->comment('Sanctum 个人访问令牌表');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
