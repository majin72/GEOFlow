<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_ai_ops_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->string('title', 160)->default('');
            $table->timestamps();
        });

        Schema::create('admin_ai_ops_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_id')->constrained('admin_ai_ops_sessions')->cascadeOnDelete();
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->string('status', 32)->default('queued')->index();
            $table->text('input_text')->nullable();
            $table->json('plan')->nullable();
            $table->text('result_summary')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('admin_ai_ops_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('admin_ai_ops_runs')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('type', 32)->default('system');
            $table->string('status', 32)->default('pending')->index();
            $table->string('title', 160)->default('');
            $table->text('input_summary')->nullable();
            $table->text('output_summary')->nullable();
            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['run_id', 'position']);
        });

        Schema::create('admin_ai_ops_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('admin_ai_ops_runs')->cascadeOnDelete();
            $table->string('disk', 64)->default('local');
            $table->string('path', 500);
            $table->string('original_name', 255)->default('');
            $table->string('mime_type', 160)->default('');
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_ai_ops_attachments');
        Schema::dropIfExists('admin_ai_ops_steps');
        Schema::dropIfExists('admin_ai_ops_runs');
        Schema::dropIfExists('admin_ai_ops_sessions');
    }
};
