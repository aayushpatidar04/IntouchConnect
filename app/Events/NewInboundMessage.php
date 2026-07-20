<?php

namespace App\Events;

use App\Models\Message;
use App\Models\Company;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewInboundMessage implements ShouldBroadcastNow
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
     *
     * Notification ownership follows the assigned executive,
     * not the company that owns the shared WhatsApp session.
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
                "admin-notifications.{$assignedCompanyId}"
            );
        }

        if ($assignedExecutiveId > 0) {
            $channels[] = new Channel(
                "executive-notifications.{$assignedExecutiveId}"
            );
        }

        return $channels;
    }

    /*
     * Normal sessions:
     *
     * Notification ownership follows the company whose WhatsApp
     * session received the message.
     */
    $receivingCompanyId = (int) Company::where(
        'slug',
        $sessionId
    )->value('id');

    /*
     * Fallback in case the company slug cannot be resolved.
     */
    if ($receivingCompanyId <= 0) {
        $receivingCompanyId =
            (int) $this->message->company_id;
    }

    if ($receivingCompanyId > 0) {
        $channels[] = new Channel(
            "admin-notifications.{$receivingCompanyId}"
        );
    }

    $recipientExecutiveId = null;

    /*
     * Current owner received the message.
     */
    if (
        $customer->assignedTo &&
        (int) $customer->assignedTo->company_id ===
            $receivingCompanyId
    ) {
        $recipientExecutiveId =
            (int) $customer->assignedTo->id;
    }

    /*
     * Customer was transferred but message arrived on
     * the old owner's company session.
     */
    elseif (
        $customer->oldOwner &&
        (int) $customer->oldOwner->company_id ===
            $receivingCompanyId
    ) {
        $recipientExecutiveId =
            (int) $customer->oldOwner->id;
    }

    if ($recipientExecutiveId) {
        $channels[] = new Channel(
            "executive-notifications.{$recipientExecutiveId}"
        );
    }

    return $channels;
}

    public function broadcastAs(): string
    {
        return 'new.message';
    }

    public function broadcastWith(): array
    {
        $message = $this->message;
        $customer = $message->customer;

        $recipientExecutiveId =
            $this->resolveRecipientExecutiveId();

        return [
            'message_id' => $message->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,

            'body' => $message->body ?: (
                $message->type !== 'text'
                    ? "[{$message->type}]"
                    : ''
            ),

            'type' => $message->type,
            'has_document' => $message->document !== null,

            /*
             * This should represent the actual notification
             * recipient—not always customer.assigned_to.
             */
            'recipient_executive_id' =>
                $recipientExecutiveId,

            'assigned_to' =>
                $customer->assigned_to,

            'old_owner_id' =>
                $customer->old_owner_id,

            'receiving_company_id' =>
                $message->company_id,

            'session_id' =>
                $message->session_id,

            'is_unassigned' =>
                $recipientExecutiveId === null,

            'created_at' =>
                $message->created_at->toISOString(),
        ];
    }

    private function resolveRecipientExecutiveId(): ?int
    {
        $customer = $this->message->customer;
        $receivingCompanyId =
            (int) $this->message->company_id;

        if (
            $customer?->assignedTo &&
            (int) $customer->assignedTo->company_id ===
                $receivingCompanyId
        ) {
            return (int) $customer->assignedTo->id;
        }

        if (
            $customer?->oldOwner &&
            (int) $customer->oldOwner->company_id ===
                $receivingCompanyId
        ) {
            return (int) $customer->oldOwner->id;
        }

        return null;
    }
}
