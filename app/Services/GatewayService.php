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
            \Log::info($response);
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
        if (!$this->company) {
            return ['status' => 'disconnected', 'is_ready' => false];
        }

        $all = $this->getStatus($this->company->session_id);

        if (empty($all)) {
            return ['status' => 'disconnected', 'is_ready' => false];
        }

        // Trust gateway completely — it now does real-time health checks
        return $all;
    }

    public function sendMessage(string $to, string $message, ?int $messageId = null, int $priority = 0): array
    {
        if (!$this->company) {
            throw new \RuntimeException('No company context set on GatewayService.');
        }

        $message_data = Message::findOrFail($messageId);

        $response = Http::withHeaders($this->headers())
            ->timeout(10)
            ->post("{$this->baseUrl}/send", [
                'sessionId' => $message_data->session_id,
                'to' => $to,
                'message' => $message,
                'message_id' => $messageId,
                'priority' => $priority,
            ]);
        \Log::info($response->json());

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
        string $mimeType = '',
        ?int $messageId = null
    ): array {
        if (!$this->company) {
            throw new \RuntimeException('No company context set on GatewayService.');
        }
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Temp file not found: {$filePath}");
        }

        $filename = $originalFilename ?: basename($filePath);
        $message = Message::findOrFail($messageId);
        $response = Http::withHeaders($this->headers())
            ->timeout(60)
            ->attach('file', file_get_contents($filePath), $filename)
            ->post("{$this->baseUrl}/send-media", [
                'sessionId' => $message->session_id,
                'to' => $to,
                'caption' => $caption,
                'original_filename' => $filename,
                'mime_type' => $mimeType,
                'message_id' => $messageId,
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

        \Log::info('Gateway createSession request', [
            'endpoint' => $endpoint,
            'sessionId' => $sessionId
        ]);

        try {
            $response = Http::withHeaders($this->headers())
                ->timeout(15)
                ->post($endpoint, ['sessionId' => $sessionId]);

            \Log::info("Gateway createSession response", [
                'sessionId' => $sessionId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            if (!$response->successful()) {
                $errorBody = $response->json();
                $errorMsg = $errorBody['error'] ?? $response->body() ?? 'Unknown error';
                throw new \RuntimeException("Gateway returned {$response->status()}: {$errorMsg}");
            }

            return $response->json();

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            \Log::error('Gateway connection failed', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Cannot connect to WhatsApp gateway. Is it running?');
        } catch (\Throwable $e) {
            \Log::error('Gateway request failed', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
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

        // ── Normalize phone ──────────────────────────────────────────────────
        $phone = preg_replace('/\D/', '', $data['from'] ?? '');

        if (empty($phone)) {

            Log::warning(
                'Incoming message with empty/invalid phone number',
                $data
            );

            return;
        }

        // ── Arihant special handling ─────────────────────────────────────────
        $isArihant = strtolower(trim($company?->name ?? '')) === 'arihant_special_session';

        // ── Customer Lookup ──────────────────────────────────────────────────
	$customerQuery = Customer::withoutGlobalScopes()
	    ->with([
        	'companyData' => function ($query) {
            	$query->withoutGlobalScopes();
       	     },
    	])->where('phone', $phone);

        // Normal companies → scoped lookup
        // if (!$isArihant && $company) {

        //     $customerQuery->where('company_id', $company->id);
        // }

        // Arihant → global lookup
        $customer = $customerQuery->first();

        // ── Create Customer If Not Found ─────────────────────────────────────
        if (!$customer) {

            // Round robin assignment
            $assignedUser = $company
                ? app(\App\Services\CustomerAssignmentService::class)
                    ->assignExecutive($company, $isArihant)
                : null;

            $customerCompanyId = $isArihant
                ? $assignedUser?->company_id
                : $company?->id;

            $customer = Customer::withoutGlobalScopes()->create([
                'company_id' => $customerCompanyId,
                'assigned_to' => $assignedUser?->id,
                'name' => 'Unknown (' . $phone . ')',
                'phone' => $phone,
                'status' => 'active',
            ]);

            Log::info(
                "Auto-created customer for phone {$phone} in company " .
                ($company?->id ?? 'unknown')
            );
        }

        // ── Save Message ─────────────────────────────────────────────────────
        if ($isArihant) {
            $sessionId = "arihant-special-session";
        } else {
            $sessionId = $company?->slug;
        }
        $message = Message::withoutGlobalScopes()->create([
            'company_id' => $customer->company_id,
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

        // Update customer last contact
        $customer->update([
            'last_contacted_at' => now()
        ]);

        // ── Handle Media/Documents ───────────────────────────────────────────
	\Log::info($data);
	if (!empty($data['has_media']) && !empty($data['media'])) {
            try {
                $mediaData = $data['media'];

                Log::info('Processing inbound WhatsApp media', [
                    'message_id' => $message->id,
                    'customer_id' => $customer->id,
                    'filename' => $mediaData['filename'] ?? null,
                    'mimetype' => $mediaData['mimetype'] ?? null,
                    'size_bytes' => $mediaData['size_bytes'] ?? null,
                    'has_inline_data' => !empty($mediaData['data']),
                    'crm_media_url' => $mediaData['crm_media_url'] ?? null,
                ]);

                // Inline base64 media
                if (!empty($mediaData['data'])) {
                    $document = app(DocumentService::class)->saveFromWhatsApp(
                        customer: $customer,
                        message: $message,
                        mediaData: $mediaData
                    );

                    Log::info('Inbound inline media saved', [
                        'document_id' => $document?->id,
                        'message_id' => $message->id,
                    ]);
                }

                // Large media already uploaded by gateway
                elseif (!empty($mediaData['crm_media_url'])) {
                    $crmUrl = $mediaData['crm_media_url'];

                    $urlPath = parse_url($crmUrl, PHP_URL_PATH);

                    if (!$urlPath) {
                        throw new \RuntimeException(
                            'Invalid CRM media URL: ' . $crmUrl
                        );
                    }

                    $relativePath = ltrim($urlPath, '/');

                    if (str_starts_with($relativePath, 'storage/')) {
                        $relativePath = substr(
                            $relativePath,
                            strlen('storage/')
                        );
                    }

                    if (empty($relativePath)) {
                        throw new \RuntimeException(
                            'Could not resolve media path from CRM URL'
                        );
                    }

                    $document = Document::withoutGlobalScopes()->create([
                        'company_id' => $customer->company_id,
                        'customer_id' => $customer->id,
                        'message_id' => $message->id,

                        'stored_filename' =>
                            $mediaData['stored_filename']
                            ?? basename($relativePath),

                        'original_filename' =>
                            $mediaData['filename']
                            ?? basename($relativePath),

                        'disk' => 'public',
                        'path' => $relativePath,

                        'mime_type' =>
                            $mediaData['mimetype']
                            ?? 'application/octet-stream',

                        'size' => (int) (
                            $mediaData['size_bytes']
                            ?? $mediaData['size']
                            ?? 0
                        ),

                        'source' => 'whatsapp',
                        'status' => 'completed',
                    ]);

                    Log::info('Inbound large media document created', [
                        'document_id' => $document->id,
                        'message_id' => $message->id,
                        'path' => $document->path,
                    ]);
                }

                // Media metadata is incomplete
                else {
                    Log::warning('Inbound media payload has no usable content', [
                        'message_id' => $message->id,
                        'customer_id' => $customer->id,
                        'media' => $mediaData,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('Failed to save inbound media', [
                    'message_id' => $message->id ?? null,
                    'customer_id' => $customer->id ?? null,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'media' => $data['media'] ?? null,
                ]);
            }
        }

        // ── Load Relations ───────────────────────────────────────────────────
        $message->load('customer', 'document');

        // ── Broadcasts ───────────────────────────────────────────────────────
        try {

            broadcast(
                new \App\Events\NewMessageReceived($message)
            );

        } catch (\Throwable $e) {

            Log::warning(
                'NewMessageReceived broadcast failed: ' .
                $e->getMessage()
            );
        }

        try {

            broadcast(
                new \App\Events\NewInboundMessage($message)
            );

        } catch (\Throwable $e) {

            Log::warning(
                'NewInboundMessage broadcast failed: ' .
                $e->getMessage()
            );
        }

        // ── Audit ────────────────────────────────────────────────────────────
        AuditLog::create([
            'company_id' => $customer->company_id,
            'action' => 'message.received',
            'auditable_type' => Message::class,
            'auditable_id' => $message->id,
            'new_values' => [
                'from' => $phone,
                'type' => $message->type,
            ],
        ]);
    }


    // ── Outbound status updates ───────────────────────────────────────────────

    private function handleMessageSent(array $data): void
    {
        $messageId = $data['message_id'] ?? null;

        if ($messageId) {
            Message::withoutGlobalScopes()
                ->where('id', $messageId)
                ->update(['status' => 'sent']);
        }

        try {
            broadcast(new \App\Events\MessageStatusUpdated([
                'message_id' => $messageId,
                'status' => 'sent',
            ]));
        } catch (\Throwable $e) {
            Log::warning('MessageStatusUpdated broadcast failed: ' . $e->getMessage());
        }
    }

    private function handleMessageFailed(array $data): void
    {
        $messageId = $data['message_id'] ?? null;

        if ($messageId) {
            Message::withoutGlobalScopes()
                ->where('id', $messageId)
                ->update([
                    'status' => 'failed',
                    'failure_reason' => $data['error'] ?? 'Unknown',
                ]);
        }

        try {
            broadcast(new \App\Events\MessageStatusUpdated([
                'message_id' => $messageId,
                'status' => 'failed',
                'error' => $data['error'] ?? null,
            ]));
        } catch (\Throwable $e) {
            Log::warning('MessageStatusUpdated broadcast failed: ' . $e->getMessage());
        }
    }

    private function handleMessageAck(array $data): void
    {
        $ack = (int) ($data['ack'] ?? -1);

        $incomingStatus = match ($ack) {
            0 => 'failed',
            1 => 'sent',
            2 => 'delivered',
            3 => 'read',
            default => null,
        };

        if (!$incomingStatus) {
            Log::warning('Unknown message ACK received', [
                'ack' => $ack,
                'data' => $data,
            ]);

            return;
        }

        $localMessageId = $data['message_id'] ?? null;
        $waMessageId = $data['wa_message_id'] ?? null;

        if (!$localMessageId && !$waMessageId) {
            Log::warning(
                'Message ACK received without identifiers',
                ['data' => $data]
            );

            return;
        }

        /*
         * Prefer the local database ID because it is unambiguous.
         * Fall back to the WhatsApp message ID.
         */
        $message = Message::withoutGlobalScopes()
            ->when(
                $localMessageId,
                fn($query) => $query->where('id', $localMessageId)
            )
            ->when(
                !$localMessageId && $waMessageId,
                fn($query) => $query->where(
                    'whatsapp_message_id',
                    $waMessageId
                )
            )
            ->first();

        if (!$message) {
            Log::warning('Message not found for ACK', [
                'local_message_id' => $localMessageId,
                'wa_message_id' => $waMessageId,
                'ack' => $ack,
                'data' => $data,
            ]);

            return;
        }

        $currentStatus = $message->status;

        /*
         * Status progression:
         *
         * pending → queued → sent → delivered → read
         */
        $statusRanks = [
            'pending' => 0,
            'queued' => 1,
            'sent' => 2,
            'delivered' => 3,
            'read' => 4,
        ];

        $currentRank = $statusRanks[$currentStatus] ?? 0;
        $incomingRank = $statusRanks[$incomingStatus] ?? 0;

        /*
         * A timeout or delayed error must never replace a confirmed
         * delivered/read state.
         */
        if (
            $incomingStatus === 'failed' &&
            in_array(
                $currentStatus,
                ['delivered', 'read'],
                true
            )
        ) {
            Log::warning(
                'Ignored stale failure ACK for successful message',
                [
                    'message_id' => $message->id,
                    'wa_message_id' => $waMessageId,
                    'current_status' => $currentStatus,
                    'incoming_status' => $incomingStatus,
                    'reason' => $data['reason'] ?? null,
                ]
            );

            return;
        }

        /*
         * Ignore normal regressions such as:
         *
         * read → delivered
         * delivered → sent
         */
        if (
            $incomingStatus !== 'failed' &&
            $incomingRank < $currentRank
        ) {
            Log::debug('Ignored regressive message ACK', [
                'message_id' => $message->id,
                'current_status' => $currentStatus,
                'incoming_status' => $incomingStatus,
            ]);

            return;
        }

        $updates = [
            'status' => $incomingStatus,
        ];

        if (
            $waMessageId &&
            empty($message->whatsapp_message_id)
        ) {
            $updates['whatsapp_message_id'] = $waMessageId;
        }

        if (
            $incomingStatus === 'sent' &&
            empty($message->sent_at)
        ) {
            $updates['sent_at'] = now();
        }

        if (
            $incomingStatus === 'delivered' &&
            empty($message->delivered_at)
        ) {
            $updates['delivered_at'] = now();
        }

        if (
            $incomingStatus === 'read' &&
            empty($message->read_at)
        ) {
            $updates['read_at'] = now();

            if (empty($message->delivered_at)) {
                $updates['delivered_at'] = now();
            }
        }

        if ($incomingStatus === 'failed') {
            $updates['failure_reason'] =
                $data['reason']
                ?? $data['error']
                ?? 'Delivery failed';
        } else {
            /*
             * Clear an earlier temporary error after receiving a
             * successful ACK.
             */
            $updates['failure_reason'] = null;
        }

        $message->update($updates);

        Log::info('Message ACK processed', [
            'message_id' => $message->id,
            'wa_message_id' =>
                $waMessageId
                ?? $message->whatsapp_message_id,
            'old_status' => $currentStatus,
            'new_status' => $incomingStatus,
            'ack' => $ack,
            'source' => $data['source'] ?? null,
        ]);

        try {
            broadcast(
                new \App\Events\MessageStatusUpdated([
                    /*
                     * The frontend most likely identifies the message
                     * using the local database ID.
                     */
                    'message_id' => $message->id,
                    'wa_message_id' =>
                        $waMessageId
                        ?? $message->whatsapp_message_id,
                    'status' => $incomingStatus,
                    'ack' => $ack,
                ])
            );
        } catch (\Throwable $e) {
            Log::warning(
                'MessageStatusUpdated broadcast failed: ' .
                $e->getMessage()
            );
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
            broadcast(new \App\Events\WhatsAppStatusChanged('qr_ready', $payload['qr'] ?? null, $sessionId));
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
            broadcast(new \App\Events\WhatsAppStatusChanged('connected', null, $sessionId, $payload['phone'] ?? null));
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
            broadcast(new \App\Events\WhatsAppStatusChanged('disconnected', null, $sessionId));
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
            broadcast(new \App\Events\WhatsAppStatusChanged('failed', null, $sessionId));
        } catch (\Throwable $e) {
            Log::warning('WhatsAppStatusChanged broadcast failed: ' . $e->getMessage());
        }
    }
}
