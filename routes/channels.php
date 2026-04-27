<?php
// routes/channels.php
// CHANGED: Added admin-notifications and executive-notifications channels

use Illuminate\Support\Facades\Broadcast;

// ── Public channels (no auth — gateway events + chat updates) ────────────────
Broadcast::channel('messages',         fn() => true);
Broadcast::channel('whatsapp-status',  fn() => true);

// ── Notification channels ─────────────────────────────────────────────────────
// Admin sees ALL inbound notifications
Broadcast::channel('admin-notifications', function ($user) {
    return $user->hasRole('admin') || $user->hasRole('auditor');
});

// Executive only sees their own customer notifications
Broadcast::channel('executive-notifications.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// ── Per-user private channel (chat window updates) ────────────────────────────
Broadcast::channel('user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});