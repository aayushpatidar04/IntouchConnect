<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Message;
use App\Services\AuditService;
use App\Services\GatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function __construct(private GatewayService $gateway)
    {
    }

    public function send(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        $data = $request->validate([
            'body' => 'required|string|max:4096',
        ]);

        $user = auth()->user();

        // Resolve correct session
        $sessionId = app(\App\Services\MessageRoutingService::class)->resolveOutgoingSession($customer, $user);

        \Log::info('Message send initiated', [
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'resolved_session_id' => $sessionId,
            'user_company_id' => $user->company_id,
            'user_company_session' => $user->company?->session_id,
        ]);

        // Set company context so GatewayService uses the right sessionId
        $this->gateway->forAuthUser();

        // Check this company's gateway session is live
        $status = $this->gateway->getCompanyStatus();

        \Log::info('Gateway status check', [
            'status' => $status,
            'company_id' => $user->company_id,
        ]);

        if (empty($status['is_ready'])) {
            $sessionStatus = $status['status'] ?? 'unknown';
            $errorMsg = match ($sessionStatus) {
                'disconnected' => 'WhatsApp is disconnected. Please reconnect from settings.',
                'qr_ready' => 'WhatsApp QR code pending. Please scan the QR code.',
                'connecting' => 'WhatsApp is connecting. Please wait a moment.',
                'failed' => 'WhatsApp authentication failed. Please reconnect.',
                'default' => "WhatsApp is not ready (status: {$sessionStatus}). Please check connection.",
            };

            return response()->json(['error' => $errorMsg], 503);
        }

        $message = Message::create([
            'company_id' => auth()->user()->company_id,
            'session_id' => $sessionId,
            'customer_id' => $customer->id,
            'sent_by' => auth()->id(),
            'direction' => 'outbound',
            'type' => 'text',
            'body' => $data['body'],
            'status' => 'pending',
        ]);

        try {
            $result = $this->gateway->sendMessage($customer->phone, $data['body'], $message->id);
            \Log::info('Gateway sendMessage result', ['result' => $result]);

            $message->refresh();

            $updateData = [
                'gateway_job_id' =>
                    $result['job_id'] ?? null,

                'whatsapp_message_id' =>
                    $result['wa_message_id'] ?? null,
            ];

            if (
                in_array(
                    $message->status,
                    ['pending', 'queued'],
                    true
                )
            ) {
                $updateData['status'] = 'queued';
            }

            $message->update($updateData);
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
            \Log::error('Gateway sendMessage failed', [
                'message_id' => $message->id,
                'error' => $errorMessage,
                'session_id' => $sessionId,
                'phone' => $customer->phone,
            ]);

            $message->update([
                'status' => 'failed',
                'failure_reason' => $errorMessage
            ]);

            // Return the ACTUAL error to the UI
            return response()->json([
                'error' => 'Message failed: ' . $errorMessage
            ], 503);
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
