<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappSession extends Model
{
    protected $fillable = [
        'company_id',
        'session_id',
        'phone',
        'status',
        'qr_code',
        'connected_at',
        'disconnected_at',
        'disconnect_reason',
    ];

    protected function casts(): array
    {
        return [
            'connected_at'    => 'datetime',
            'disconnected_at' => 'datetime',
        ];
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Get or initialise the session record for a given company.
     * Uses updateOrCreate so we never have thousands of duplicate rows.
     */
    public static function forCompany(Company $company): static
    {
        return static::firstOrNew([
            'company_id' => $company->id,
            'session_id' => $company->session_id,
        ]) ?? new static([
            'company_id' => $company->id,
            'session_id' => $company->session_id,
            'status'     => 'disconnected',
        ]);
    }

    /**
     * Upsert session state for a company by session_id.
     * This replaces the old "WhatsappSession::create()" that grew unboundedly.
     */
    public static function upsertForSession(string $sessionId, array $data): static
    {
        return static::updateOrCreate(
            ['session_id' => $sessionId],
            $data
        );
    }

    public function isConnected(): bool
    {
        return $this->status === 'connected';
    }
}