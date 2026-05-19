<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Template extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'created_by',
        'name',
        'body',
        'variables',
        'category',
        'is_active',
        'media',
    ];

    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'is_active' => 'boolean',
        ];
    }

    // ── Global scope ──────────────────────────────────────────────────────────
    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope());

        static::creating(function ($template) {
            if (empty($template->company_id) && auth()->check()) {
                $template->company_id = auth()->user()->company_id;
            }
            if (empty($template->created_by) && auth()->check()) {
                $template->created_by = auth()->id();
            }
            // Auto-extract variables from body on create
            $template->variables = static::extractVariables($template->body);
        });

        static::updating(function ($template) {
            if ($template->isDirty('body')) {
                $template->variables = static::extractVariables($template->body);
            }
        });
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Executives who have been explicitly assigned this template.
     * If this pivot is EMPTY, all executives in the company can use it.
     */
    public function assignedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'template_user')
                    ->withTimestamps();
    }

    public function broadcasts(): HasMany
    {
        return $this->hasMany(TemplateBroadcast::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Extract {{variable}} placeholders from the body string.
     * Returns a unique sorted list of variable names.
     */
    public static function extractVariables(string $body): array
    {
        preg_match_all('/\{\{(\w+)\}\}/', $body, $matches);
        return array_values(array_unique($matches[1]));
    }

    /**
     * Replace {{variable}} placeholders with provided values.
     * Any variable not in $values is left as-is.
     */
    public function resolve(array $values): string
    {
        $body = $this->body;
        foreach ($values as $key => $value) {
            $body = str_replace('{{' . $key . '}}', $value, $body);
        }
        return $body;
    }

    /**
     * Check if a user is allowed to use this template.
     * Logic: if no explicit assignments → all company execs can use it.
     *        if assignments exist → only assigned users (+ admins always).
     */
    public function isAccessibleBy(User $user): bool
    {
        if ($user->hasAnyRole(['admin', 'super_admin'])) return true;
        $assignedCount = $this->assignedUsers()->count();
        if ($assignedCount === 0) return true; // open to all
        return $this->assignedUsers()->where('user_id', $user->id)->exists();
    }

    /**
     * Category label + color for UI badge.
     */
    public static function categoryMeta(): array
    {
        return [
            'general'  => ['label' => 'General',  'color' => 'bg-surface-100 text-surface-600'],
            'followup' => ['label' => 'Follow-up', 'color' => 'bg-blue-100 text-blue-700'],
            'promo'    => ['label' => 'Promo',     'color' => 'bg-purple-100 text-purple-700'],
            'reminder' => ['label' => 'Reminder',  'color' => 'bg-amber-100 text-amber-700'],
        ];
    }
}