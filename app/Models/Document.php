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
        return route('documents.download', $this->id);
    }

    public function getFormattedSizeAttribute(): string
    {
        $bytes = $this->size;
        if ($bytes < 1024) return "{$bytes} B";
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / 1048576, 1) . ' MB';
    }

    public function getIconAttribute(): string
    {
        return match(true) {
            str_contains($this->mime_type, 'pdf')   => 'document-text',
            str_contains($this->mime_type, 'image') => 'photo',
            str_contains($this->mime_type, 'video') => 'video-camera',
            str_contains($this->mime_type, 'audio') => 'musical-note',
            default                                  => 'paper-clip',
        };
    }
}