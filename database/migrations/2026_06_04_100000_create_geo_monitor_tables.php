<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_monitor_projects', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->string('slug', 120)->unique();
            $table->string('brand_name', 160)->default('');
            $table->string('primary_domain', 255)->default('');
            $table->json('competitor_domains')->nullable();
            $table->json('competitor_brands')->nullable();
            $table->json('product_keywords')->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('geo_monitor_platforms', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('label', 80);
            $table->string('selector_version', 80)->default('');
            $table->string('chat_url', 500)->default('');
            $table->boolean('is_enabled')->default(true)->index();
            $table->json('config')->nullable();
            $table->timestamps();
        });

        Schema::create('geo_monitor_proxy_endpoints', function (Blueprint $table): void {
            $table->id();
            $table->string('label', 120);
            $table->string('proxy_type', 32)->default('http');
            $table->string('host', 255);
            $table->unsignedSmallInteger('port')->default(0);
            $table->string('region', 64)->nullable();
            $table->string('egress_ip_summary', 120)->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamp('cooldown_until')->nullable();
            $table->timestamp('last_health_check_at')->nullable();
            $table->string('last_health_status', 32)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('geo_monitor_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_id')->constrained('geo_monitor_platforms')->cascadeOnDelete();
            $table->string('external_id', 120);
            $table->string('label', 160)->default('');
            $table->string('status', 40)->default('active')->index();
            $table->string('profile_storage_path', 500)->default('');
            $table->foreignId('proxy_endpoint_id')->nullable()->constrained('geo_monitor_proxy_endpoints')->nullOnDelete();
            $table->unsignedSmallInteger('daily_quota')->nullable();
            $table->unsignedSmallInteger('hourly_quota')->nullable();
            $table->timestamp('cooldown_until')->nullable();
            $table->timestamp('last_login_check_at')->nullable();
            $table->string('last_login_status', 40)->nullable();
            $table->text('last_error_message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['platform_id', 'external_id']);
        });

        Schema::create('geo_monitor_browser_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->unique()->constrained('geo_monitor_accounts')->cascadeOnDelete();
            $table->string('profile_key', 120);
            $table->string('storage_path', 500);
            $table->string('host_node', 120)->nullable();
            $table->string('user_agent_summary', 255)->nullable();
            $table->string('locale', 16)->default('zh-CN');
            $table->string('timezone_id', 64)->default('Asia/Shanghai');
            $table->json('viewport')->nullable();
            $table->string('health_status', 32)->default('unknown')->index();
            $table->timestamp('last_maintained_at')->nullable();
            $table->string('last_maintenance_via', 32)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('geo_monitor_prompts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('geo_monitor_projects')->cascadeOnDelete();
            $table->string('code', 80);
            $table->text('prompt_text');
            $table->string('intent', 40)->default('generic')->index();
            $table->json('keywords')->nullable();
            $table->string('target_product', 160)->nullable();
            $table->string('target_article_url', 500)->nullable();
            $table->string('locale', 16)->default('zh-CN');
            $table->string('region', 64)->nullable();
            $table->unsignedSmallInteger('priority')->default(0);
            $table->boolean('is_enabled')->default(true)->index();
            $table->timestamps();

            $table->unique(['project_id', 'code']);
        });

        Schema::create('geo_monitor_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('geo_monitor_projects')->cascadeOnDelete();
            $table->foreignId('triggered_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('status', 32)->default('pending')->index();
            $table->json('platform_scope')->nullable();
            $table->unsignedInteger('prompt_count')->default(0);
            $table->unsignedInteger('observation_count')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->text('failed_summary')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('geo_monitor_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('geo_monitor_runs')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('geo_monitor_projects')->cascadeOnDelete();
            $table->foreignId('prompt_id')->constrained('geo_monitor_prompts')->cascadeOnDelete();
            $table->foreignId('platform_id')->constrained('geo_monitor_platforms')->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('geo_monitor_accounts')->nullOnDelete();
            $table->text('prompt_text_snapshot');
            $table->string('status', 40)->index();
            $table->string('login_status', 40)->default('unknown');
            $table->longText('answer_text')->nullable();
            $table->char('answer_hash', 64)->nullable()->index();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('screenshot_path', 500)->nullable();
            $table->string('html_path', 500)->nullable();
            $table->string('raw_text_path', 500)->nullable();
            $table->string('markdown_path', 500)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('probed_at')->nullable();
            $table->timestamps();

            $table->index(['run_id', 'platform_id']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('geo_monitor_citations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('observation_id')->constrained('geo_monitor_observations')->cascadeOnDelete();
            $table->string('url', 2000);
            $table->string('domain', 255)->default('')->index();
            $table->string('title', 500)->nullable();
            $table->text('snippet')->nullable();
            $table->string('source_type', 40)->default('link');
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_own_domain')->default(false)->index();
            $table->boolean('is_competitor_domain')->default(false)->index();
            $table->text('evidence_snippet')->nullable();
            $table->timestamps();

            $table->index(['observation_id', 'position']);
        });

        Schema::create('geo_monitor_mentions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('observation_id')->constrained('geo_monitor_observations')->cascadeOnDelete();
            $table->string('entity_name', 160);
            $table->string('entity_type', 32)->default('other')->index();
            $table->text('mention_text');
            $table->string('sentiment', 16)->nullable();
            $table->text('context_snippet')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_recommendation')->default(false);
            $table->timestamps();

            $table->index(['observation_id', 'position']);
        });

        Schema::create('geo_monitor_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('geo_monitor_projects')->cascadeOnDelete();
            $table->foreignId('run_id')->nullable()->constrained('geo_monitor_runs')->nullOnDelete();
            $table->foreignId('observation_id')->nullable()->constrained('geo_monitor_observations')->nullOnDelete();
            $table->string('score_version', 32)->default('v1');
            $table->json('metrics');
            $table->timestamp('computed_at');
            $table->timestamps();

            $table->index(['project_id', 'computed_at']);
        });

        Schema::create('geo_monitor_resource_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('observation_id')->unique()->constrained('geo_monitor_observations')->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('geo_monitor_accounts')->nullOnDelete();
            $table->foreignId('browser_profile_id')->nullable()->constrained('geo_monitor_browser_profiles')->nullOnDelete();
            $table->foreignId('proxy_endpoint_id')->nullable()->constrained('geo_monitor_proxy_endpoints')->nullOnDelete();
            $table->string('scheduler_strategy', 64)->nullable();
            $table->timestamp('assigned_at');
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('geo_monitor_profile_maintenance_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->nullable()->constrained('geo_monitor_accounts')->nullOnDelete();
            $table->foreignId('browser_profile_id')->nullable()->constrained('geo_monitor_browser_profiles')->nullOnDelete();
            $table->foreignId('proxy_endpoint_id')->nullable()->constrained('geo_monitor_proxy_endpoints')->nullOnDelete();
            $table->string('trigger_reason', 40)->index();
            $table->string('maintenance_via', 32)->default('other');
            $table->string('status', 32)->default('in_progress')->index();
            $table->foreignId('operator_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('egress_ip_summary', 120)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        $now = now();
        DB::table('geo_monitor_platforms')->insert([
            [
                'code' => 'doubao',
                'label' => '豆包',
                'selector_version' => '2026-06-03-poc-v5-doubao-fast',
                'chat_url' => 'https://www.doubao.com/chat/',
                'is_enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'deepseek',
                'label' => 'DeepSeek',
                'selector_version' => '2026-06-03-poc-v1',
                'chat_url' => 'https://chat.deepseek.com/',
                'is_enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'yuanbao',
                'label' => '腾讯元宝',
                'selector_version' => '2026-06-03-poc-v4-yuanbao-online',
                'chat_url' => 'https://yuanbao.tencent.com/chat',
                'is_enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_monitor_profile_maintenance_events');
        Schema::dropIfExists('geo_monitor_resource_assignments');
        Schema::dropIfExists('geo_monitor_scores');
        Schema::dropIfExists('geo_monitor_mentions');
        Schema::dropIfExists('geo_monitor_citations');
        Schema::dropIfExists('geo_monitor_observations');
        Schema::dropIfExists('geo_monitor_runs');
        Schema::dropIfExists('geo_monitor_prompts');
        Schema::dropIfExists('geo_monitor_browser_profiles');
        Schema::dropIfExists('geo_monitor_accounts');
        Schema::dropIfExists('geo_monitor_proxy_endpoints');
        Schema::dropIfExists('geo_monitor_platforms');
        Schema::dropIfExists('geo_monitor_projects');
    }
};
