<?php
// app/Events/NewInboundMessage.php
// NEW FILE — fires when an inbound WhatsApp message arrives.
// Notifies: admin channel always + assigned executive's private channel.
// Separate from NewMessageReceived to carry richer notification payload.

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewInboundMessage implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message) {}

    public function broadcastOn(): array
    {
        $channels = [];

        // Always broadcast to the admin notification channel
        $channels[] = new Channel('admin-notifications');

        // Also broadcast to the assigned executive's channel if there is one
        $assignedTo = $this->message->customer->assigned_to ?? null;
        if ($assignedTo) {
            $channels[] = new Channel("executive-notifications.{$assignedTo}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'new.message';
    }

    public function broadcastWith(): array
    {
        $msg      = $this->message;
        $customer = $msg->customer;

        return [
            'message_id'    => $msg->id,
            'customer_id'   => $customer->id,
            'customer_name' => $customer->name,
            'customer_phone'=> $customer->phone,
            'body'          => $msg->body ?: ($msg->type !== 'text' ? "[{$msg->type}]" : ''),
            'type'          => $msg->type,
            'has_document'  => $msg->document !== null,
            'assigned_to'   => $customer->assigned_to,
            'is_unassigned' => $customer->assigned_to === null,
            'created_at'    => $msg->created_at->toISOString(),
        ];
    }
}