<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'assigned_to',
        'name',
        'phone',
        'email',
        'company',
        'notes',
        'status',
        'tags',
        'last_contacted_at',
    ];

    protected function casts(): array
    {
        return [
            'tags'              => 'array',
            'last_contacted_at' => 'datetime',
        ];
    }

    // ── Global Scope: automatically filter by the authenticated user's company ─
    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope());

        // Auto-fill company_id when creating
        static::creating(function ($customer) {
            if (empty($customer->company_id) && auth()->check() && auth()->user()->company_id) {
                $customer->company_id = auth()->user()->company_id;
            }
        });
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at');
    }

    /**
     * Fixed: was HasMany with limit(1) which breaks eager loading on lists.
     * HasOne with latestOfMany() works correctly with with('latestMessage').
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getUnreadCountAttribute(): int
    {
        return $this->messages()
            ->where('direction', 'inbound')
            ->whereNull('read_at')
            ->count();
    }

    public function getFormattedPhoneAttribute(): string
    {
        return '+' . ltrim($this->phone, '+');
    }
}