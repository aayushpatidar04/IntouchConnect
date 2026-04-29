<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WhatsAppStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $status,
        public ?string $qrCode = null,
        public ?string $sessionId = null,   // CHANGED: added session_id
        public ?string $phone = null,   // CHANGED: added phone for 'connected' event
    ) {
    }

    public function broadcastOn(): array
    {
        // CHANGED: Use session-scoped channel so only the right company receives it.
        // Falls back to 'whatsapp-status.default' if no session_id provided.
        $sessionId = $this->sessionId ?? config('whatsapp.session_id', 'default');

        return [new Channel("whatsapp-status.{$sessionId}")];
    }

    public function broadcastAs(): string
    {
        return 'status.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'status' => $this->status,
            'qr' => $this->qrCode,
            'session_id' => $this->sessionId ?? config('whatsapp.session_id', 'default'),
            'phone' => $this->phone,
        ];
    }
}