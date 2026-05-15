<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_ai_ops_tool_approvals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('run_id')->constrained('admin_ai_ops_runs')->cascadeOnDelete();
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->string('tool_name', 128);
            $table->longText('arguments_json');
            $table->string('args_fingerprint', 64);
            $table->string('risk_label', 160)->nullable();
            $table->string('status', 32)->default('pending')->index();
            $table->timestamp('expires_at');
            $table->timestamp('decided_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->longText('executed_output')->nullable();
            $table->timestamps();

            $table->index(['run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_ai_ops_tool_approvals');
    }
};
