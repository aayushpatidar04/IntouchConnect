<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TemplateBroadcast extends Model
{
    protected $fillable = [
        'company_id',
        'template_id',
        'sent_by',
        'variable_values',
        'total_recipients',
        'sent_count',
        'failed_count',
        'pending_count',
        'status',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'variable_values' => 'array',
            'started_at'      => 'datetime',
            'completed_at'    => 'datetime',
        ];
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(TemplateBroadcastRecipient::class, 'broadcast_id');
    }

    // ── Computed ──────────────────────────────────────────────────────────────

    public function getProgressPercentAttribute(): int
    {
        if ($this->total_recipients === 0) return 0;
        return (int) round((($this->sent_count + $this->failed_count) / $this->total_recipients) * 100);
    }

    public function markComplete(): void
    {
        $this->update([
            'status'       => 'completed',
            'completed_at' => now(),
        ]);
    }
}