<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Message;
use App\Models\WhatsappSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GatewayService
{
    private string $baseUrl;
    private string $secret;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('whatsapp.gateway_url'), '/');
        $this->secret = config('whatsapp.gateway_secret');
    }

    private function headers(): array
    {
        return ['X-Gateway-Secret' => $this->secret];
    }

    public function getStatus(): array
    {
        try {
            $response = Http::withHeaders($this->headers())
                ->timeout(5)
                ->get("{$this->baseUrl}/status");

            return $response->json() ?? ['status' => 'disconnected', 'is_ready' => false];
        } catch (\Throwable $e) {
            Log::error('Gateway status check failed: ' . $e->getMessage());
            return ['status' => 'unreachable', 'is_ready' => false];
        }
    }

    public function sendMessage(string $to, string $message, int $priority = 0): array
    {
        $response = Http::withHeaders($this->headers())
            ->timeout(10)
            ->post("{$this->baseUrl}/send", [
                'to' => $to,
                'message' => $message,
                'priority' => $priority,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Gateway send failed: ' . $response->body());
        }

        return $response->json();
    }

    public function sendMedia(string $to, string $filePath, string $caption = '', string $originalFilename = '', string $mimeType = ''): array
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Temp file not found: {$filePath}");
        }

        // Use the original filename for the multipart part so the gateway
        // can preserve it; fall back to the basename of the temp file.
        $filename = $originalFilename ?: basename($filePath);

        $response = Http::withHeaders($this->headers())
            ->timeout(60)
            ->attach('file', file_get_contents($filePath), $filename)
            ->post("{$this->baseUrl}/send-media", [
                'to' => $to,
                'caption' => $caption,
                'original_filename' => $filename,
                'mime_type' => $mimeType,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Gateway send-media failed: ' . $response->body());
        }

        return $response->json();
    }

    public function logout(): bool
    {
        try {
            $response = Http::withHeaders($this->headers())
                ->timeout(10)
                ->post("{$this->baseUrl}/logout");
            return $response->successful();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getQueueStats(): array
    {
        try {
            return Http::withHeaders($this->headers())
                ->timeout(5)
                ->get("{$this->baseUrl}/queue/stats")
                ->json() ?? [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ─── Webhook Handler ──────────────────────────────────────────────────────
    public function handleWebhook(array $payload): void
    {
        $event = $payload['event'] ?? null;

        match ($event) {
            'incoming_message' => $this->handleIncomingMessage($payload['data']),
            'message_sent' => $this->handleMessageSent($payload['data']),
            'message_failed' => $this->handleMessageFailed($payload['data']),
            'message_ack' => $this->handleMessageAck($payload['data']),
            'qr_generated' => $this->handleQrGenerated($payload),
            'session_ready' => $this->handleSessionReady($payload),
            'session_disconnected' => $this->handleSessionDisconnected($payload),
            'auth_failure' => $this->handleAuthFailure($payload),
            default => Log::warning("Unknown gateway event: {$event}"),
        };
    }

    private function handleIncomingMessage(array $data): void
    {
        // ── Deduplicate: ignore if we already stored this WA message ─────────
        $waMessageId = $data['message_id'] ?? null;
        if ($waMessageId && Message::where('whatsapp_message_id', $waMessageId)->exists()) {
            Log::debug("Duplicate WA message ignored: {$waMessageId}");
            return;
        }

        $phone = preg_replace('/\D/', '', $data['from']);
        $customer = Customer::where('phone', $phone)->first();

        if (!$customer) {
            // Auto-create customer from unknown number
            $customer = Customer::create([
                'name' => "Unknown ({$phone})",
                'phone' => $phone,
                'status' => 'active',
            ]);
        }

        $message = Message::create([
            'customer_id' => $customer->id,
            'whatsapp_message_id' => $waMessageId,
            'direction' => 'inbound',
            'type' => $data['type'] ?? 'text',
            'body' => $data['body'] ?? '',
            'status' => 'delivered',
            'is_forwarded' => $data['is_forwarded'] ?? false,
            'delivered_at' => now(),
        ]);

        $customer->update(['last_contacted_at' => now()]);

        // Handle media/document attachment
        if (!empty($data['has_media']) && !empty($data['media'])) {
            app(DocumentService::class)->saveFromWhatsApp(
                customer: $customer,
                message: $message,
                mediaData: $data['media']
            );
        }

        // Broadcast real-time update to assigned executive
        $message->load('customer', 'document');
        
        broadcast(new \App\Events\NewMessageReceived($message));
        broadcast(new \App\Events\NewInboundMessage($message));

        AuditLog::create([
            'action' => 'message.received',
            'auditable_type' => Message::class,
            'auditable_id' => $message->id,
            'new_values' => ['from' => $phone, 'type' => $message->type],
        ]);
    }

    private function handleMessageSent(array $data): void
    {
        // Message has been successfully sent to WhatsApp servers (not yet delivered to device)
        Message::where('gateway_job_id', $data['job_id'])
            ->update(['status' => 'sent']);

        broadcast(new \App\Events\MessageStatusUpdated([
            'job_id' => $data['job_id'],
            'status' => 'sent',
        ]));
    }

    private function handleMessageFailed(array $data): void
    {
        Message::where('gateway_job_id', $data['job_id'])
            ->update(['status' => 'failed', 'failure_reason' => $data['error'] ?? 'Unknown']);

        broadcast(new \App\Events\MessageStatusUpdated($data));
    }

    private function handleMessageAck(array $data): void
    {
        $status = match ((int) ($data['ack'] ?? 0)) {
            1 => 'sent',
            2 => 'delivered',
            3 => 'read',
            default => null,
        };

        if ($status) {
            $updates = ['status' => $status];
            if ($status === 'delivered')
                $updates['delivered_at'] = now();
            if ($status === 'read')
                $updates['read_at'] = now();

            Message::where('whatsapp_message_id', $data['message_id'])->update($updates);
            broadcast(new \App\Events\MessageStatusUpdated($data + ['status' => $status]));
        }
    }

    private function handleQrGenerated(array $payload): void
    {
        WhatsappSession::create([
            'status' => 'qr_ready',
            'qr_code' => $payload['qr'] ?? null,
        ]);

        broadcast(new \App\Events\WhatsAppStatusChanged('qr_ready', $payload['qr'] ?? null));
    }

    private function handleSessionReady(array $payload): void
    {
        WhatsappSession::create([
            'status' => 'connected',
            'phone' => $payload['phone'] ?? null,
            'connected_at' => now(),
            'qr_code' => null,
        ]);

        broadcast(new \App\Events\WhatsAppStatusChanged('connected'));
    }

    private function handleSessionDisconnected(array $payload): void
    {
        WhatsappSession::create([
            'status' => 'disconnected',
            'disconnected_at' => now(),
            'disconnect_reason' => $payload['reason'] ?? null,
        ]);

        broadcast(new \App\Events\WhatsAppStatusChanged('disconnected'));
    }

    private function handleAuthFailure(array $payload): void
    {
        WhatsappSession::create(['status' => 'failed']);
        broadcast(new \App\Events\WhatsAppStatusChanged('failed'));
    }
}