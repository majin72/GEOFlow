<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_ai_ops_tool_approvals', function (Blueprint $table): void {
            if (! Schema::hasColumn('admin_ai_ops_tool_approvals', 'tool_call_id')) {
                $table->string('tool_call_id', 128)->nullable()->after('tool_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('admin_ai_ops_tool_approvals', function (Blueprint $table): void {
            if (Schema::hasColumn('admin_ai_ops_tool_approvals', 'tool_call_id')) {
                $table->dropColumn('tool_call_id');
            }
        });
    }
};
