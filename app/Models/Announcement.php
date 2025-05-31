<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class Announcement extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'content',
        'importance_level',
        'created_by',
        'expires_at',
        'is_active',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    // Importance level constants
    const IMPORTANCE_LOW = 1;    // 3 days
    const IMPORTANCE_MEDIUM = 2; // 1 month
    const IMPORTANCE_HIGH = 3;   // 1 year

    /**
     * Boot method to automatically set expires_at based on importance_level
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($announcement) {
            if (!$announcement->expires_at) {
                $announcement->expires_at = static::calculateExpirationDate($announcement->importance_level);
            }
        });

        static::updating(function ($announcement) {
            if ($announcement->isDirty('importance_level')) {
                $announcement->expires_at = static::calculateExpirationDate($announcement->importance_level);
            }
        });
    }

    /**
     * Calculate expiration date based on importance level
     */
    public static function calculateExpirationDate(int $importanceLevel): Carbon
    {
        return match ($importanceLevel) {
            self::IMPORTANCE_LOW => now()->addDays(3),
            self::IMPORTANCE_MEDIUM => now()->addMonth(),
            self::IMPORTANCE_HIGH => now()->addYear(),
            default => now()->addDays(3),
        };
    }

    /**
     * Get the departments that the announcement belongs to
     */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class)->withTimestamps();
    }

    /**
     * Get the user who created the announcement
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scope to get only active announcements
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
                    ->where('expires_at', '>', now());
    }

    /**
     * Scope to get announcements for specific departments
     */
    public function scopeForDepartments(Builder $query, array $departmentIds): Builder
    {
        return $query->whereHas('departments', function ($query) use ($departmentIds) {
            $query->whereIn('departments.id', $departmentIds);
        });
    }

    /**
     * Scope to get announcements for a single department
     */
    public function scopeForDepartment(Builder $query, int $departmentId): Builder
    {
        return $query->whereHas('departments', function ($query) use ($departmentId) {
            $query->where('departments.id', $departmentId);
        });
    }

    /**
     * Scope to get announcements by importance level
     */
    public function scopeByImportance(Builder $query, int $importanceLevel): Builder
    {
        return $query->where('importance_level', $importanceLevel);
    }

    /**
     * Check if the announcement is still valid (not expired)
     */
    public function isValid(): bool
    {
        return $this->is_active && $this->expires_at > now();
    }

    /**
     * Get the importance level as text
     */
    public function getImportanceLevelTextAttribute(): string
    {
        return match ($this->importance_level) {
            self::IMPORTANCE_LOW => 'Low (3 days)',
            self::IMPORTANCE_MEDIUM => 'Medium (1 month)',
            self::IMPORTANCE_HIGH => 'High (1 year)',
            default => 'Unknown',
        };
    }

    /**
     * Get days remaining until expiration
     */
    public function getDaysRemainingAttribute(): int
    {
        return max(0, now()->diffInDays($this->expires_at, false));
    }
}
