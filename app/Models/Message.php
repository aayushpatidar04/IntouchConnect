<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'session_id',
        'customer_id',
        'sent_by',
        'whatsapp_message_id',
        'direction',
        'type',
        'body',
        'status',
        'gateway_job_id',
        'failure_reason',
        'is_forwarded',
        'delivered_at',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'read_at'      => 'datetime',
            'is_forwarded' => 'boolean',
        ];
    }

    // ── Global Scope ──────────────────────────────────────────────────────────
    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope());

        static::creating(function ($message) {
            if (empty($message->company_id) && auth()->check() && auth()->user()->company_id) {
                $message->company_id = auth()->user()->company_id;
            }
        });
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function document(): HasOne
    {
        return $this->hasOne(Document::class);
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getIsInboundAttribute(): bool
    {
        return $this->direction === 'inbound';
    }

    public function getIsOutboundAttribute(): bool
    {
        return $this->direction === 'outbound';
    }
}