<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'gateway_url', 'gateway_secret', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function users(): HasMany     { return $this->hasMany(User::class); }
    public function customers(): HasMany { return $this->hasMany(Customer::class); }
    public function sessions(): HasMany  { return $this->hasMany(WhatsappSession::class); }

    public function currentSession(): ?WhatsappSession
    {
        return $this->sessions()->latest()->first();
    }
}