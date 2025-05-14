<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveQuota extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'year',
        'total_quota',
        'used_quota',
        'remaining_quota'
    ];

    /**
     * Get the user that owns the leave quota
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Update the remaining quota based on total and used quotas
     */
    public function updateRemainingQuota(): void
    {
        $this->remaining_quota = $this->total_quota - $this->used_quota;
        $this->save();
    }

    /**
     * Scope a query to only include quotas for the current year
     */
    public function scopeCurrentYear($query)
    {
        return $query->where('year', now()->year);
    }
}
