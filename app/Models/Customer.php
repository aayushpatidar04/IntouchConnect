<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'assigned_to',
	'old_owner_id',
        'name',
        'phone',
        'email',
        'company',
        'notes',
        'status',
        'tags',
        'last_contacted_at',
	'bitrix_lead_id',
    	'bitrix_assigned_by_id',
    	'bitrix_created_at',
    	'bitrix_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'tags'              => 'array',
            'last_contacted_at' => 'datetime',
	    'bitrix_created_at' => 'datetime',
	    'bitrix_synced_at' => 'datetime',
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

    public function companyData(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function oldOwner()
    {
        return $this->belongsTo(User::class, 'old_owner_id');
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

    public function groups()
    {
        return $this->belongsToMany(Group::class, 'customer_group');
    }

    public function scopeVisibleTo(
        Builder $query,
        User $user
    ): Builder {
        if ($user->isSuperAdmin()) {
            return $query;
        }

        if ($user->hasRole('executive')) {
            return $query->where(function (Builder $q) use ($user) {
                $q->where('assigned_to', $user->id)
                    ->orWhere('old_owner_id', $user->id);
            });
        }

        if ($user->hasAnyRole(['admin', 'auditor'])) {
            return $query->where(function (Builder $q) use ($user) {
                $q->where('company_id', $user->company_id)

                    ->orWhereHas('assignedTo', function (Builder $userQuery) use ($user) {
                        $userQuery->where(
                            'company_id',
                            $user->company_id
                        );
                    })
 
                    ->orWhereHas('oldOwner', function (Builder $userQuery) use ($user) {
                        $userQuery->where(
                            'company_id',
                            $user->company_id
                        );
                    });
            });
        }

        return $query->whereRaw('1 = 0');
    }

    public function resolveRouteBindingQuery(
        $query,
        $value,
        $field = null
    ) {
        return $query
            ->withoutGlobalScope(CompanyScope::class)
            ->where(
                $field ?? $this->getRouteKeyName(),
                $value
            );
    }
}
