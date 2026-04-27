<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Message extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new \App\Scopes\CompanyScope());

        static::creating(function ($customer) {
            if (auth()->check() && !$customer->company_id) {
                $customer->company_id = auth()->user()->company_id;
            }
        });
    }

    protected $fillable = [
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
        'company_id',
    ];

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'is_forwarded' => 'boolean',
        ];
    }

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

    public function getIsInboundAttribute(): bool
    {
        return $this->direction === 'inbound';
    }

    public function getIsOutboundAttribute(): bool
    {
        return $this->direction === 'outbound';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}