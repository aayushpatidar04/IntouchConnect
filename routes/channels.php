<?php
// routes/channels.php
// CHANGED: Added admin-notifications and executive-notifications channels

use Illuminate\Support\Facades\Broadcast;

// ── Public channels (no auth — gateway events + chat updates) ────────────────
Broadcast::channel('messages',         fn() => true);
Broadcast::channel('whatsapp-status.{sessionId}', fn() => true);

// ── Notification channels ─────────────────────────────────────────────────────
// Admin sees ALL inbound notifications
Broadcast::channel(
    'admin-notifications.{companyId}',
    function ($user, $companyId) {

        // Super admin always allowed
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Only admins/auditors
        if (
            !$user->hasRole('admin') &&
            !$user->hasRole('auditor')
        ) {
            return false;
        }

        // Check company access
        return $user->accessibleCompanies()
            ->where('companies.id', $companyId)
            ->exists();
    }
);

// Executive only sees their own customer notifications
Broadcast::channel('executive-notifications.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// ── Per-user private channel (chat window updates) ────────────────────────────
Broadcast::channel('user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
