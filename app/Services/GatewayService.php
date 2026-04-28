<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GatewayService
{
    private string $baseUrl;
    private string $secret;
    private ?Company $company = null;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('whatsapp.gateway_url'), '/');
        $this->secret = config('whatsapp.gateway_secret');
    }

    // ── Tenant context ────────────────────────────────────────────────────────

    /**
     * Set the company context for this request.
     * Used when processing webhooks or sending messages on behalf of a company.
     */
    public function setCompany(Company $company): static
    {
        $this->company = $company;
        return $this;
    }

    /**
     * Resolve company from the authenticated user (for outbound messages).
     */
    public function forAuthUser(): static
    {
        if (auth()->check() && auth()->user()->company_id) {
            $this->company = auth()->user()->company;
        }
        return $this;
    }

    private function headers(): array
    {
        return ['X-Gateway-Secret' => $this->secret];
    }

    // ── Gateway API calls ─────────────────────────────────────────────────────

    /**
     * Get status of all sessions (returns array keyed by session_id).
     * Or a specific session if session_id is passed.
     */
    public function getStatus(?string $sessionId = null): array
    {
        try {
            $url = $sessionId
                ? "{$this->baseUrl}/status/{$sessionId}"
                : "{$this->baseUrl}/status";

            $response = Http::withHeaders($this->headers())
                ->timeout(5)
                ->get($url);

            return $response->json() ?? [];
        } catch (\Throwable $e) {
            Log::error('Gateway status check failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get status for the current company's session.
     */
    public function getCompanyStatus(): array
    {
        if (!$this->company)
            return ['status' => 'disconnected', 'is_ready' => false];
        $all = $this->getStatus($this->company->session_id);
        return $all ?: ['status' => 'disconnected', 'is_ready' => false];
    }

    public function sendMessage(string $to, string $message, int $priority = 0): array
    {
        if (!$this->company) {
            throw new \RuntimeException('No company context set on GatewayService.');
        }

        $response = Http::withHeaders($this->headers())
            ->timeout(10)
            ->post("{$this->baseUrl}/send", [
                'sessionId' => $this->company->session_id,
                'to' => $to,
                'message' => $message,
                'priority' => $priority,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Gateway send failed: ' . $response->body());
        }

        return $response->json();
    }

    public function sendMedia(
        string $to,
        string $filePath,
        string $caption = '',
        string $originalFilename = '',
        string $mimeType = ''
    ): array {
        if (!$this->company) {
            throw new \RuntimeException('No company context set on GatewayService.');
        }
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Temp file not found: {$filePath}");
        }

        $filename = $originalFilename ?: basename($filePath);

        $response = Http::withHeaders($this->headers())
            ->timeout(60)
            ->attach('file', file_get_contents($filePath), $filename)
            ->post("{$this->baseUrl}/send-media", [
                'sessionId' => $this->company->session_id,
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

    public function logout(string $sessionId): bool
    {
        try {
            $response = Http::withHeaders($this->headers())
                ->timeout(10)
                ->post("{$this->baseUrl}/logout", ['sessionId' => $sessionId]);
            return $response->successful();
        } catch (\Throwable $e) {
            Log::error('Gateway logout failed: ' . $e->getMessage());
            return false;
        }
    }

    public function createSession(string $sessionId): array
    {
        $endpoint = "{$this->baseUrl}/session/create";
        \Log::info('Gateway createSession request', ['endpoint' => $endpoint, 'sessionId' => $sessionId]);

        $response = Http::withHeaders($this->headers())
            ->timeout(15)
            ->post($endpoint, ['sessionId' => $sessionId]);

        \Log::info("Gateway createSession response for sessionId {$sessionId}", ['status' => $response->status(), 'body' => $response->body()]);
        if (!$response->successful()) {
            throw new \RuntimeException(sprintf(
                'Gateway session create failed: status=%s body=%s',
                $response->status(),
                $response->body()
            ));
        }

        return $response->json();
    }

    public function deleteSession(string $sessionId): bool
    {
        try {
            $response = Http::withHeaders($this->headers())
                ->timeout(10)
                ->delete("{$this->baseUrl}/session/{$sessionId}");
            return $response->successful();
        } catch (\Throwable $e) {
            Log::error('Gateway session delete failed: ' . $e->getMessage());
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

    // ── Webhook dispatcher ────────────────────────────────────────────────────

    public function handleWebhook(array $payload): void
    {
        $event = $payload['event'] ?? null;
        $sessionId = $payload['session_id'] ?? null;

        // Resolve which company this session belongs to
        $company = $sessionId
            ? Company::where('slug', $sessionId)->where('is_active', true)->first()
            : null;

        if (!$company && $sessionId) {
            Log::warning("Webhook received for unknown session_id: {$sessionId}", ['event' => $event]);
            // Don't throw — still try to handle without company context for session events
        }

        if ($company) {
            $this->setCompany($company);
        }

        match ($event) {
            'incoming_message' => $this->handleIncomingMessage($payload['data'] ?? [], $company),
            'message_sent' => $this->handleMessageSent($payload['data'] ?? []),
            'message_failed' => $this->handleMessageFailed($payload['data'] ?? []),
            'message_ack' => $this->handleMessageAck($payload['data'] ?? []),
            'qr_generated' => $this->handleQrGenerated($payload, $company),
            'session_ready' => $this->handleSessionReady($payload, $company),
            'session_disconnected' => $this->handleSessionDisconnected($payload, $company),
            'auth_failure' => $this->handleAuthFailure($payload, $company),
            default => Log::warning("Unknown gateway event: {$event}"),
        };
    }

    // ── Inbound message ───────────────────────────────────────────────────────

    private function handleIncomingMessage(array $data, ?Company $company): void
    {
        // ── Deduplicate ───────────────────────────────────────────────────────
        $waMessageId = $data['message_id'] ?? null;
        if ($waMessageId) {
            $exists = Message::withoutGlobalScopes()
                ->where('whatsapp_message_id', $waMessageId)
                ->exists();
            if ($exists) {
                Log::debug("Duplicate WA message ignored: {$waMessageId}");
                return;
            }
        }

        // ── Normalise phone ───────────────────────────────────────────────────
        // IMPORTANT: This is the fix for "messages from unknown numbers not arriving".
        // WhatsApp sends numbers in various formats: "919876543210", "91 9876543210",
        // "+91-9876543210" etc. We strip everything except digits.
        $phone = preg_replace('/\D/', '', $data['from'] ?? '');

        if (empty($phone)) {
            Log::warning('Incoming message with empty/invalid phone number', $data);
            return;
        }

        // ── Find or auto-create customer ──────────────────────────────────────
        // withoutGlobalScopes() so we can search by phone + company_id explicitly
        // without the CompanyScope interfering (no auth context in webhook).
        $customer = Customer::withoutGlobalScopes()
            ->where('phone', $phone)
            ->when($company, fn($q) => $q->where('company_id', $company->id))
            ->first();

        if (!$customer) {
            // Auto-create customer from unknown/new number.
            // Assign to a default admin or first executive of the company.
            $defaultAssignee = $company
                ? User::where('company_id', $company->id)
                    ->whereHas('roles', fn($q) => $q->whereIn('name', ['admin', 'executive']))
                    ->orderByRaw("
              CASE name
                  WHEN 'admin' THEN 1
                  WHEN 'executive' THEN 2
                  ELSE 3
              END
          ")
                    ->value('id')
                : null;


            $customer = Customer::withoutGlobalScopes()->create([
                'company_id' => $company?->id,
                'assigned_to' => $defaultAssignee,
                'name' => 'Unknown (' . $phone . ')',
                'phone' => $phone,
                'status' => 'active',
            ]);

            Log::info("Auto-created customer for phone {$phone} in company " . ($company?->id ?? 'unknown'));
        }

        // ── Save message ──────────────────────────────────────────────────────
        $sessionId = $data['session_id'] ?? ($this->company?->session_id);

        $message = Message::withoutGlobalScopes()->create([
            'company_id' => $company?->id,
            'session_id' => $sessionId,
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

        // ── Handle media/document attachment ──────────────────────────────────
        if (!empty($data['has_media']) && !empty($data['media'])) {
            try {
                $mediaData = $data['media'];

                if (!empty($mediaData['data'])) {
                    // Inline base64 case
                    app(DocumentService::class)->saveFromWhatsApp(
                        customer: $customer,
                        message: $message,
                        mediaData: $mediaData
                    );
                } else {
                    // CRM reference case (large file)
                    $crmUrl = $mediaData['crm_media_url'] ?? '';
                    $relativePath = '';

                    if ($crmUrl) {
                        // Strip everything before and including "storage/"
                        $relativePath = preg_replace('#^.+?/storage/#', '', $crmUrl);
                    }


                    $document = new Document([
                        'customer_id' => $customer->id,
                        'message_id' => $message->id,
                        'stored_filename' => $mediaData['filename'] ?? 'attachment',
                        'original_filename' => $mediaData['filename'] ?? 'attachment',
                        'disk' => 'public',
                        'path' => $relativePath,
                        'mime_type' => $mediaData['mimetype'] ?? 'application/octet-stream',
                        'size' => '> 4MB',
                        'source' => 'whatsapp',
                        'status' => 'pending',
                    ]);
                    $document->save();
                }

            } catch (\Throwable $e) {
                Log::error('Failed to save inbound media: ' . $e->getMessage());
                // Don't throw — message was saved, media failure is non-fatal
            }
        }

        // ── Broadcast real-time updates (wrapped — never crash the webhook) ───
        $message->load('customer', 'document');

        try {
            broadcast(new \App\Events\NewMessageReceived($message));
        } catch (\Throwable $e) {
            Log::warning('NewMessageReceived broadcast failed: ' . $e->getMessage());
        }

        try {
            broadcast(new \App\Events\NewInboundMessage($message));
        } catch (\Throwable $e) {
            Log::warning('NewInboundMessage broadcast failed: ' . $e->getMessage());
        }

        // ── Audit ─────────────────────────────────────────────────────────────
        AuditLog::create([
            'company_id' => $company?->id,
            'action' => 'message.received',
            'auditable_type' => Message::class,
            'auditable_id' => $message->id,
            'new_values' => ['from' => $phone, 'type' => $message->type],
        ]);
    }

    // ── Outbound status updates ───────────────────────────────────────────────

    private function handleMessageSent(array $data): void
    {
        Message::withoutGlobalScopes()
            ->where('gateway_job_id', $data['job_id'])
            ->update(['status' => 'sent']);

        try {
            broadcast(new \App\Events\MessageStatusUpdated([
                'job_id' => $data['job_id'],
                'status' => 'sent',
            ]));
        } catch (\Throwable $e) {
            Log::warning('MessageStatusUpdated broadcast failed: ' . $e->getMessage());
        }
    }

    private function handleMessageFailed(array $data): void
    {
        Message::withoutGlobalScopes()
            ->where('gateway_job_id', $data['job_id'])
            ->update(['status' => 'failed', 'failure_reason' => $data['error'] ?? 'Unknown']);

        try {
            broadcast(new \App\Events\MessageStatusUpdated($data));
        } catch (\Throwable $e) {
            Log::warning('MessageStatusUpdated broadcast failed: ' . $e->getMessage());
        }
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

            Message::withoutGlobalScopes()
                ->where('whatsapp_message_id', $data['message_id'])
                ->update($updates);

            try {
                broadcast(new \App\Events\MessageStatusUpdated($data + ['status' => $status]));
            } catch (\Throwable $e) {
                Log::warning('MessageStatusUpdated broadcast failed: ' . $e->getMessage());
            }
        }
    }

    // ── Session state handlers ────────────────────────────────────────────────

    private function handleQrGenerated(array $payload, ?Company $company): void
    {
        $sessionId = $payload['session_id'] ?? null;

        WhatsappSession::upsertForSession($sessionId, [
            'company_id' => $company?->id,
            'status' => 'qr_ready',
            'qr_code' => $payload['qr'] ?? null,
            'disconnected_at' => null,
            'disconnect_reason' => null,
        ]);

        try {
            broadcast(new \App\Events\WhatsAppStatusChanged('qr_ready', $payload['qr'] ?? null));
        } catch (\Throwable $e) {
            Log::warning('WhatsAppStatusChanged broadcast failed: ' . $e->getMessage());
        }
    }

    private function handleSessionReady(array $payload, ?Company $company): void
    {
        $sessionId = $payload['session_id'] ?? null;

        WhatsappSession::upsertForSession($sessionId, [
            'company_id' => $company?->id,
            'status' => 'connected',
            'phone' => $payload['phone'] ?? null,
            'connected_at' => now(),
            'qr_code' => null,
            'disconnected_at' => null,
            'disconnect_reason' => null,
        ]);

        try {
            broadcast(new \App\Events\WhatsAppStatusChanged('connected'));
        } catch (\Throwable $e) {
            Log::warning('WhatsAppStatusChanged broadcast failed: ' . $e->getMessage());
        }
    }

    private function handleSessionDisconnected(array $payload, ?Company $company): void
    {
        $sessionId = $payload['session_id'] ?? null;

        WhatsappSession::upsertForSession($sessionId, [
            'company_id' => $company?->id,
            'status' => 'disconnected',
            'disconnected_at' => now(),
            'disconnect_reason' => $payload['reason'] ?? null,
            'qr_code' => null,
        ]);

        try {
            broadcast(new \App\Events\WhatsAppStatusChanged('disconnected'));
        } catch (\Throwable $e) {
            Log::warning('WhatsAppStatusChanged broadcast failed: ' . $e->getMessage());
        }
    }

    private function handleAuthFailure(array $payload, ?Company $company): void
    {
        $sessionId = $payload['session_id'] ?? null;

        WhatsappSession::upsertForSession($sessionId, [
            'company_id' => $company?->id,
            'status' => 'failed',
        ]);

        try {
            broadcast(new \App\Events\WhatsAppStatusChanged('failed'));
        } catch (\Throwable $e) {
            Log::warning('WhatsAppStatusChanged broadcast failed: ' . $e->getMessage());
        }
    }
}