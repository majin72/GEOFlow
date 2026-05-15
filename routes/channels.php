<?php

use App\Models\AdminAiOpsRun;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('admin.ai-ops.{runId}', function ($admin, int $runId): bool {
    return AdminAiOpsRun::query()
        ->whereKey($runId)
        ->where('admin_id', (int) $admin->id)
        ->exists();
}, ['guards' => ['admin']]);
