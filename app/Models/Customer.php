<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

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
        'assigned_to',
        'name',
        'phone',
        'email',
        'company',
        'notes',
        'status',
        'tags',
        'last_contacted_at',
        'company_id',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'last_contacted_at' => 'datetime',
        ];
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

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

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}