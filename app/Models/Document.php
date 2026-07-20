<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Document extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'customer_id',
        'message_id',
        'uploaded_by',
        'original_filename',
        'stored_filename',
        'disk',
        'path',
        'mime_type',
        'size',
        'source',
        'status',
        'notes',
        'encryption_key_id',
    ];

    protected $appends = [
        'download_url',
        'preview_url',
        'formatted_size',
        'media_category',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getDownloadUrlAttribute(): string
    {
        return route(
            'documents.download',
            $this->id,
            false
        );
    }

    public function getPreviewUrlAttribute(): string
    {
        return route(
            'documents.preview',
            $this->id,
            false
        );
    }

    public function getFormattedSizeAttribute(): string
    {
        $bytes = (int) $this->size;

        if ($bytes <= 0) {
            return 'Unknown size';
        }

        if ($bytes < 1024) {
            return "{$bytes} B";
        }

        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }

        if ($bytes < 1073741824) {
            return round(
                $bytes / 1048576,
                1
            ) . ' MB';
        }

        return round(
            $bytes / 1073741824,
            1
        ) . ' GB';
    }

    public function getMediaCategoryAttribute(): string
    {
        $mime = strtolower(
            $this->mime_type ?? ''
        );

        return match (true) {
            str_starts_with($mime, 'image/') =>
                'image',

            str_starts_with($mime, 'video/') =>
                'video',

            str_starts_with($mime, 'audio/') =>
                'audio',

            $mime === 'application/pdf' =>
                'pdf',

            default =>
                'document',
        };
    }

    public function getIconAttribute(): string
    {
        return match ($this->media_category) {
            'pdf' => 'document-text',
            'image' => 'photo',
            'video' => 'video-camera',
            'audio' => 'musical-note',
            default => 'paper-clip',
        };
    }
}
