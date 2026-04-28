<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Company extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function whatsappSessions(): HasMany
    {
        return $this->hasMany(WhatsappSession::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * The session_id used on the gateway = company slug.
     * e.g. slug "acme-corp" → gateway auth_info/acme-corp/ folder.
     */
    public function getSessionIdAttribute(): string
    {
        return $this->slug;
    }

    /**
     * Get the current (latest) WhatsApp session record for this company.
     */
    public function currentSession(): ?WhatsappSession
    {
        return $this->whatsappSessions()
                    ->where('session_id', $this->session_id)
                    ->latest()
                    ->first();
    }

    /**
     * Admin users of this company (role = 'admin', not 'super_admin').
     */
    public function admins(): HasMany
    {
        return $this->hasMany(User::class)->whereHas('roles', fn($q) => $q->where('name', 'admin'));
    }
}