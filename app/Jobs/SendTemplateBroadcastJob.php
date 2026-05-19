<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\Message;
use App\Models\Template;
use App\Models\TemplateBroadcast;
use App\Models\TemplateBroadcastRecipient;
use App\Services\GatewayService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * One job = one message to one customer in a broadcast.
 *
 * Why one-per-recipient?
 * - Laravel's database queue handles retries cleanly per job.
 * - We can spread the load via $delay to mimic human typing pace.
 * - Failed sends don't block the whole broadcast.
 * - Progress tracking is accurate per recipient.
 *
 * Delay strategy (set at dispatch time, not here):
 *   Recipient 1: 0s delay
 *   Recipient 2: random 30–90s
 *   Recipient 3: random 30–90s
 *   ...
 * This produces a realistic 1–2 msg/min pace which avoids WhatsApp spam detection.
 */
class SendTemplateBroadcastJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(
        public readonly int $broadcastId,
        public readonly int $recipientId,
    ) {
    }

    public function handle(GatewayService $gateway): void
    {
        $recipient = TemplateBroadcastRecipient::with(['customer', 'broadcast.template', 'broadcast.sentBy'])
            ->find($this->recipientId);

        if (!$recipient)
            return;

        // Skip if already processed (job may have been retried)
        if (in_array($recipient->status, ['sent', 'delivered', 'read', 'failed']))
            return;

        $broadcast = $recipient->broadcast;
        $customer = $recipient->customer;

        // Check gateway is still connected for this company
        $company = $broadcast->company ?? $broadcast->sentBy->company;
        $status = $gateway->setCompany($company)->getCompanyStatus();

        if (empty($status['is_ready'])) {
            // Gateway offline — release back to queue with 60s delay
            $this->release(60);
            Log::warning("Broadcast {$broadcast->id}: gateway offline, releasing job {$this->recipientId}");
            return;
        }

        // Resolve body — use pre-resolved body if already set, else resolve now
        $body = $recipient->resolved_body;

        $template = Template::find($broadcast->template_id);
        
        $type = 'text';
        $mimeType = null;

        if ($template->media) {
            $extension = Str::lower(pathinfo($template->media, PATHINFO_EXTENSION));

            // Map extension to MIME type
            $mimeMap = [
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'pdf' => 'application/pdf',
                'doc' => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xls' => 'application/vnd.ms-excel',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'ppt' => 'application/vnd.ms-powerpoint',
                'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'txt' => 'text/plain',
            ];

            $mimeType = $mimeMap[$extension] ?? 'application/octet-stream';

            // Decide type based on MIME
            if (Str::startsWith($mimeType, 'image/')) {
                $type = 'image';
            } elseif ($mimeType === 'application/pdf' || Str::startsWith($mimeType, 'application/')) {
                $type = 'document';
            } else {
                $type = 'text'; // fallback
            }
        }
        
        try {
            // Create the message record
            $message = Message::create([
                'company_id' => $broadcast->company_id,
                'session_id' => $company->session_id,
                'customer_id' => $customer->id,
                'sent_by' => $broadcast->sent_by,
                'direction' => 'outbound',
                'type' => $type,
                'body' => $body,
                'status' => 'pending',
                ]);
                

            if ($template->media) {
                $document = Document::create([
                    'customer_id' => $customer->id,
                    'message_id' => $message->id,
                    'uploaded_by' => $broadcast->sent_by,
                    'original_filename' => basename($template->media),
                    'stored_filename' => basename($template->media),
                    'disk' => 'public',
                    'path' => $template->media,
                    'mime_type' => $mimeType,
                    'size' => Storage::disk('public')->size($template->media),
                    'source' => 'template',
                    'status' => 'pending',
                    'notes' => 'Broadcast template media',
                    'company_id' => $broadcast->company_id,
                ]);

                $filePath = storage_path('app/public/' . $document->path);
                
                $result = $gateway->sendMedia(
                    to: $customer->phone,
                    filePath: $filePath,            // path to stored media file
                    caption: $body,                      // use message body as caption
                    originalFilename: $document->original_filename,
                    mimeType: $document->mime_type,
                    messageId: $message->id,
                );
            } else{
                // Send via gateway
                $result = $gateway->sendMessage($customer->phone, $body, $message->id);
            }


            $message->update([
                'status' => 'queued',
                'gateway_job_id' => $result['job_id'] ?? null,
            ]);

            $recipient->update([
                'status' => 'queued',
                'message_id' => $message->id,
                'sent_at' => now(),
            ]);

            $customer->update(['last_contacted_at' => now()]);

            // Update broadcast counters atomically
            TemplateBroadcast::where('id', $broadcast->id)
                ->increment('sent_count');
            TemplateBroadcast::where('id', $broadcast->id)
                ->decrement('pending_count');

        } catch (\Throwable $e) {
            Log::error("Broadcast {$broadcast->id} → customer {$customer->id} failed: " . $e->getMessage());

            $recipient->update([
                'status' => 'failed',
                'failure_reason' => $e->getMessage(),
            ]);

            TemplateBroadcast::where('id', $broadcast->id)
                ->increment('failed_count');
            TemplateBroadcast::where('id', $broadcast->id)
                ->decrement('pending_count');

            throw $e; // Let the queue retry
        } finally {
            // Check if broadcast is complete
            $broadcast->refresh();
            $done = $broadcast->sent_count + $broadcast->failed_count;
            if ($done >= $broadcast->total_recipients) {
                $broadcast->markComplete();
            }
        }
    }

    public function failed(\Throwable $e): void
    {
        // Permanently failed after all retries
        TemplateBroadcastRecipient::where('id', $this->recipientId)->update([
            'status' => 'failed',
            'failure_reason' => 'Max retries exceeded: ' . $e->getMessage(),
        ]);

        $broadcast = TemplateBroadcast::find($this->broadcastId);
        if ($broadcast) {
            $broadcast->refresh();
            $done = $broadcast->sent_count + $broadcast->failed_count;
            if ($done >= $broadcast->total_recipients) {
                $broadcast->markComplete();
            }
        }
    }
}