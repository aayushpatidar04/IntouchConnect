<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Document;
use App\Models\Message;
use App\Services\AuditService;
use App\Services\DocumentService;
use App\Services\GatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;

class DocumentController extends Controller
{
    public function __construct(
        private DocumentService $docService,
        private GatewayService  $gateway,
    ) {}

    public function upload(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        $request->validate([
            'file' => 'required|file|max:20480', // 20MB
        ]);

        $document = $this->docService->saveManualUpload(
            customer: $customer,
            file: $request->file('file'),
            userId: auth()->id()
        );

        AuditService::log('document.uploaded', $document, [], [
            'customer_id' => $customer->id,
            'filename' => $document->original_filename,
        ]);

        return response()->json([
            'document' => $document->load('uploadedBy'),
        ]);
    }

    public function sendToCustomer(Request $request, Customer $customer, Document $document): JsonResponse
    {
        $this->authorize('view', $customer);

        if ($document->customer_id !== $customer->id) {
            return response()->json(['error' => 'Document does not belong to this customer.'], 403);
        }

        $data = $request->validate([
            'caption' => 'nullable|string|max:1024',
        ]);

        // Check WhatsApp is connected
        $status = $this->gateway->getStatus();
        if (empty($status['is_ready'])) {
            return response()->json(['error' => 'WhatsApp is not connected.'], 503);
        }

        // Create the outbound message record linked to the document
        $message = Message::create([
            'customer_id' => $customer->id,
            'sent_by' => auth()->id(),
            'direction' => 'outbound',
            'type' => $this->typeFromMime($document->mime_type),
            'body' => $data['caption'] ?? '',
            'status' => 'pending',
        ]);

        // Link document to this outbound message
        $document->update(['message_id' => $message->id]);

        // Decrypt to a temp file so the gateway can read it
        try {
            $decrypted = $this->docService->getDecryptedContent($document);

            $safeName  = preg_replace('/[^a-zA-Z0-9._-]/', '_', $document->original_filename);
            $tmpPath   = sys_get_temp_dir() . '/' . $safeName;
            file_put_contents($tmpPath, $decrypted);

            $result = $this->gateway->sendMedia(
                to:               $customer->phone,
                filePath:         $tmpPath,
                caption:          $data['caption'] ?? '',
                originalFilename: $document->original_filename,
                mimeType:         $document->mime_type,
            );

            $message->update([
                'status' => 'queued',
                'gateway_job_id' => $result['job_id'] ?? null,
            ]);

            // Mark document as approved — executive actively sent it
            $document->update(['status' => 'approved']);

        } catch (\Throwable $e) {
            $message->update(['status' => 'failed', 'failure_reason' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to send document: ' . $e->getMessage()], 500);
        } finally {
            if (isset($tmpPath) && file_exists($tmpPath)) {
                @unlink($tmpPath);
            }
        }

        $customer->update(['last_contacted_at' => now()]);
        AuditService::log('document.sent', $document, [], [
            'customer_id' => $customer->id,
            'message_id' => $message->id,
        ]);

        return response()->json([
            'message' => $message->load('sentBy'),
            'document' => $document->fresh(),
        ]);
    }

    public function download(Document $document): Response
    {
        $this->authorize('view', $document->customer);

        AuditService::log('document.downloaded', $document);

        $content = $this->docService->getDecryptedContent($document);

        $filename = $document->original_filename;
        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        if (empty($extension)) {
            // fallback: use mime type to guess extension
            $mimeMap = [
                // Documents
                'application/pdf' => 'pdf',
                'application/msword' => 'doc',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                'application/vnd.ms-excel' => 'xls',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
                'application/vnd.ms-powerpoint' => 'ppt',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
                'text/plain' => 'txt',
                'text/csv' => 'csv',
                'application/rtf' => 'rtf',

                // Images
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                'image/bmp' => 'bmp',
                'image/tiff' => 'tiff',

                // Audio
                'audio/mpeg' => 'mp3',
                'audio/ogg' => 'ogg',
                'audio/wav' => 'wav',
                'audio/aac' => 'aac',
                'audio/flac' => 'flac',
                'audio/mp4' => 'm4a',

                // Video
                'video/mp4' => 'mp4',
                'video/3gpp' => '3gp',
                'video/x-msvideo' => 'avi',
                'video/x-matroska' => 'mkv',
                'video/webm' => 'webm',
                'video/mpeg' => 'mpeg',
            ];


            $guessed = $mimeMap[$document->mime_type] ?? null;
            if ($guessed) {
                $filename .= '.' . $guessed;
            }
        }

        return response($content, 200, [
            'Content-Type' => $document->mime_type,
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Content-Length' => strlen($content),
        ]);
    }

    public function updateStatus(Request $request, Document $document): JsonResponse
    {
        $this->authorize('update', $document->customer);

        $data = $request->validate([
            'status' => 'required|in:pending,approved,rejected',
            'notes' => 'nullable|string',
        ]);

        $old = $document->only(['status', 'notes']);
        $document->update($data);
        AuditService::log('document.status_updated', $document, $old, $data);

        return response()->json(['document' => $document]);
    }

    public function destroy(Document $document): JsonResponse
    {
        $this->authorize('update', $document->customer);
        AuditService::log('document.deleted', $document);
        $document->delete();

        return response()->json(['ok' => true]);
    }

    private function typeFromMime(string $mime): string
    {
        return match (true) {
            str_contains($mime, 'image') => 'image',
            str_contains($mime, 'video') => 'video',
            str_contains($mime, 'audio') => 'audio',
            default => 'document',
        };
    }
}