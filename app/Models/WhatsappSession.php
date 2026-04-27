<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappSession extends Model
{
    protected $fillable = [
        'phone',
        'status',
        'qr_code',
        'connected_at',
        'disconnected_at',
        'disconnect_reason',
        'company_id',
    ];

    protected function casts(): array
    {
        return [
            'connected_at' => 'datetime',
            'disconnected_at' => 'datetime',
        ];
    }

    public static function current(): static
    {
        return static::latest()->first() ?? new static(['status' => 'disconnected']);
    }

    public function isConnected(): bool
    {
        return $this->status === 'connected';
    }

    public function company(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}