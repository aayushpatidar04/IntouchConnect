<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use Carbon\Carbon;

class MessageRoutingService
{
    public function resolveOutgoingSession(
        Customer $customer,
        User $user
    ): ?string {

        // Latest inbound message
        $lastInbound = Message::query()
            ->where('customer_id', $customer->id)
            ->where('direction', 'inbound')
            ->latest()
            ->first();

        // No previous inbound
        if (!$lastInbound) {
            return $user->company?->session_id;
        }

        // Check 24-hour window
        $within24Hours =
            $lastInbound->created_at->gt(
                now()->subHours(24)
            );

        // Continue Arihant conversation
        if (
            $within24Hours &&
            str_contains(
                strtolower($lastInbound->session_id ?? ''),
                'arihant-special-session'
            )
        ) {
            return $lastInbound->session_id;
        }

        // Default company session
        return $user->company?->session_id;
    }
}
