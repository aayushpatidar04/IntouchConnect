<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

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
    ];

    protected function casts(): array
    {
        return [
            'tags'               => 'array',
            'last_contacted_at'  => 'datetime',
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

    public function latestMessage(): HasMany
    {
        return $this->hasMany(Message::class)->latest()->limit(1);
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
}