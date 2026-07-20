<?php

namespace App\Events;

use App\Models\Message;
use App\Models\Company;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewMessageReceived implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public Message $message
    ) {
        $this->message->loadMissing([
            'customer.assignedTo:id,name,company_id',
            'customer.oldOwner:id,name,company_id',
            'document',
        ]);
    }

    public function broadcastOn(): array
{
    $channels = [];

    $customer = $this->message->customer;

    if (!$customer) {
        return $channels;
    }

    $customer->loadMissing([
        'assignedTo:id,company_id',
        'oldOwner:id,company_id',
    ]);

    $sessionId = $this->message->session_id;

    /*
     * Arihant special session:
     * send message event to assigned executive and
     * assigned executive's company channel.
     */
    if ($sessionId === 'arihant-special-session') {
        $assignedExecutive = $customer->assignedTo;

        if (!$assignedExecutive) {
            return $channels;
        }

        $assignedExecutiveId =
            (int) $assignedExecutive->id;

        $assignedCompanyId =
            (int) $assignedExecutive->company_id;

        if ($assignedCompanyId > 0) {
            $channels[] = new Channel(
                "messages.company.{$assignedCompanyId}"
            );
        }

        if ($assignedExecutiveId > 0) {
            $channels[] = new Channel(
                "user.{$assignedExecutiveId}"
            );
        }

        return $channels;
    }

    /*
     * Normal company sessions.
     */
    $receivingCompanyId = (int) Company::where(
        'slug',
        $sessionId
    )->value('id');

    /*
     * Fallback when session slug is not found.
     */
    if ($receivingCompanyId <= 0) {
        $receivingCompanyId =
            (int) $this->message->company_id;
    }

    if ($receivingCompanyId > 0) {
        $channels[] = new Channel(
            "messages.company.{$receivingCompanyId}"
        );
    }

    $recipientExecutiveId = null;

    if (
        $customer->assignedTo &&
        (int) $customer->assignedTo->company_id ===
            $receivingCompanyId
    ) {
        $recipientExecutiveId =
            (int) $customer->assignedTo->id;
    } elseif (
        $customer->oldOwner &&
        (int) $customer->oldOwner->company_id ===
            $receivingCompanyId
    ) {
        $recipientExecutiveId =
            (int) $customer->oldOwner->id;
    }

    if ($recipientExecutiveId > 0) {
        $channels[] = new Channel(
            "user.{$recipientExecutiveId}"
        );
    }

    return $channels;
}

    public function broadcastAs(): string
    {
        return 'message.received';
    }

    public function broadcastWith(): array
    {
        $message = $this->message;

        return [
            'id' => $message->id,
            'customer_id' => $message->customer_id,
            'company_id' => $message->company_id,
            'session_id' => $message->session_id,
            'direction' => $message->direction,
            'type' => $message->type,
            'body' => $message->body,
            'status' => $message->status,
            'created_at' => $message->created_at,

            'customer' => [
                'id' => $message->customer->id,
                'name' => $message->customer->name,
                'assigned_to' =>
                    $message->customer->assigned_to,
                'old_owner_id' =>
                    $message->customer->old_owner_id,
            ],

            'document' => $message->document
                ? [
                    'id' =>
                        $message->document->id,

                    'original_filename' =>
                        $message->document
                            ->original_filename,

                    'mime_type' =>
                        $message->document->mime_type,

                    'formatted_size' =>
                        $message->document
                            ->formatted_size,
                ]
                : null,
        ];
    }
}
