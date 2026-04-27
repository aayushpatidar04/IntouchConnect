<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewMessageReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message) {}

    public function broadcastOn(): array
    {
        
        $assignedTo = $this->message->customer->assigned_to;
        $channels   = [new Channel('messages')];

        if ($assignedTo) {
            $channels[] = new Channel("user.{$assignedTo}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'message.received';
    }

    public function broadcastWith(): array
    {
        $msg = $this->message;
        return [
            'id'          => $msg->id,
            'customer_id' => $msg->customer_id,
            'direction'   => $msg->direction,
            'type'        => $msg->type,
            'body'        => $msg->body,
            'status'      => $msg->status,
            'created_at'  => $msg->created_at,
            'customer'    => [
                'id'   => $msg->customer->id,
                'name' => $msg->customer->name,
            ],
            'document' => $msg->document ? [
                'id'                => $msg->document->id,
                'original_filename' => $msg->document->original_filename,
                'mime_type'         => $msg->document->mime_type,
                'formatted_size'    => $msg->document->formatted_size,
            ] : null,
        ];
    }
}