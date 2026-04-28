<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Document;
use App\Models\Message;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentService
{
    public function saveFromWhatsApp(Customer $customer, Message $message, array $mediaData): Document
    {
        $base64 = $mediaData['data'];
        $mime = $mediaData['mimetype'];
        $original = $mediaData['filename'] ?? 'attachment_' . now()->timestamp;

        $decoded = base64_decode($base64);
        $ext = $this->extensionFromMime($mime);
        $stored = Str::uuid() . '.' . $ext;
        $directory = "documents/{$customer->id}/" . now()->format('Y-m');
        $path = "{$directory}/{$stored}";

        // Encrypt and store
        $encrypted = Crypt::encryptString($decoded);
        Storage::disk('public')->put($path, $encrypted);

        return Document::create([
            'customer_id' => $customer->id,
            'message_id' => $message->id,
            'original_filename' => $original,
            'stored_filename' => $stored,
            'disk' => 'public',
            'path' => $path,
            'mime_type' => $mime,
            'size' => strlen($decoded),
            'source' => 'whatsapp',
            'status' => 'pending',
        ]);
    }

    public function saveManualUpload(Customer $customer, UploadedFile $file, int $userId): Document
    {
        $original = $file->getClientOriginalName();
        $stored = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $directory = "documents/{$customer->id}/" . now()->format('Y-m');
        $path = "{$directory}/{$stored}";

        $content = file_get_contents($file->getRealPath());
        $encrypted = Crypt::encryptString($content);
        Storage::disk('public')->put($path, $encrypted);

        return Document::create([
            'customer_id' => $customer->id,
            'uploaded_by' => $userId,
            'original_filename' => $original,
            'stored_filename' => $stored,
            'disk' => 'public',
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'source' => 'manual_upload',
            'status' => 'pending',
        ]);
    }

    public function getDecryptedContent(Document $document): string
    {
        $encrypted = Storage::disk($document->disk)->get($document->path);

        if (!$encrypted) {
            throw new \RuntimeException("Document file not found: {$document->path}");
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable $e) {
            // If decryption fails, assume it's a raw file (large files)
            return $encrypted;
        }
    }

    private function extensionFromMime(string $mime): string
    {
        return match (true) {
            str_contains($mime, 'pdf') => 'pdf',
            str_contains($mime, 'jpeg') => 'jpg',
            str_contains($mime, 'png') => 'png',
            str_contains($mime, 'gif') => 'gif',
            str_contains($mime, 'webp') => 'webp',
            str_contains($mime, 'mp4') => 'mp4',
            str_contains($mime, 'ogg') => 'ogg',
            str_contains($mime, 'mpeg') => 'mp3',
            str_contains($mime, 'msword') => 'doc',
            str_contains($mime, 'spreadsheet') => 'xlsx',
            str_contains($mime, 'openxmlformats') => 'docx',
            default => 'bin',
        };
    }
}