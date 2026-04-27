<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Message;
use App\Services\AuditService;
use App\Services\GatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function __construct(private GatewayService $gateway) {}

    public function send(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        $data = $request->validate([
            'body' => 'required|string|max:4096',
        ]);
        
        // Check gateway is live
        $status = $this->gateway->getStatus();
        
        if (empty($status['is_ready'])) {
            return response()->json(['error' => 'WhatsApp is not connected.'], 503);
        }

        // Create message record (pending)
        $message = Message::create([
            'customer_id' => $customer->id,
            'sent_by'     => auth()->id(),
            'direction'   => 'outbound',
            'type'        => 'text',
            'body'        => $data['body'],
            'status'      => 'pending',
        ]);

        try {
            $gateway = new GatewayService(auth()->user()->company);
            $result = $gateway->sendMessage($customer->phone, $data['body']);
            $message->update([
                'status'          => 'queued',
                'gateway_job_id'  => $result['job_id'] ?? null,
            ]);
        } catch (\Throwable $e) {
            $message->update(['status' => 'failed', 'failure_reason' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to queue message.'], 500);
        }

        $customer->update(['last_contacted_at' => now()]);
        AuditService::log('message.sent', $message, [], ['to' => $customer->phone, 'body' => $data['body']]);

        return response()->json([
            'message' => $message->load('sentBy'),
        ]);
    }

    public function markRead(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        $customer->messages()
            ->where('direction', 'inbound')
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'status' => 'read']);

        return response()->json(['ok' => true]);
    }

    public function history(Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        $messages = $customer->messages()
            ->with(['sentBy:id,name', 'document'])
            ->orderBy('created_at')
            ->get();

        return response()->json($messages);
    }
}