<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'reason',
        'start_date',
        'end_date',
        'status'
    ];

    const UPDATED_AT = null; // Only uses created_at timestamp

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'created_at' => 'datetime',
    ];

    protected $appends = [
        'duration',
        'has_proofs',
        'proofs_count'
    ];

    /**
     * Get the user that owns the leave request
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the proofs for this leave request
     */
    public function proofs(): HasMany
    {
        return $this->hasMany(LeaveRequestProof::class);
    }

    /**
     * Get only verified proofs for this leave request
     */
    public function verifiedProofs(): HasMany
    {
        return $this->hasMany(LeaveRequestProof::class)->where('is_verified', true);
    }

    /**
     * Get only unverified proofs for this leave request
     */
    public function unverifiedProofs(): HasMany
    {
        return $this->hasMany(LeaveRequestProof::class)->where('is_verified', false);
    }

    /**
     * Calculate the duration of the leave request in days
     */
    public function getDurationAttribute(): int
    {
        return $this->start_date->diffInDays($this->end_date) + 1;
    }

    /**
     * Check if this leave request has any proofs
     */
    public function getHasProofsAttribute(): bool
    {
        return $this->proofs()->exists();
    }

    /**
     * Get the count of proofs for this leave request
     */
    public function getProofsCountAttribute(): int
    {
        return $this->proofs()->count();
    }

    /**
     * Check if this leave request has verified proofs
     */
    public function hasVerifiedProofs(): bool
    {
        return $this->verifiedProofs()->exists();
    }

    /**
     * Check if this leave request requires proof
     * For 'sakit' type, proof is usually required
     */
    public function requiresProof(): bool
    {
        return $this->type === 'sakit';
    }

    /**
     * Scope a query to only include pending leave requests
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope a query to only include approved leave requests
     */
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    /**
     * Scope a query to only include rejected leave requests
     */
    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    /**
     * Scope to include leave requests with proofs
     */
    public function scopeWithProofs($query)
    {
        return $query->whereHas('proofs');
    }

    /**
     * Scope to include leave requests without proofs
     */
    public function scopeWithoutProofs($query)
    {
        return $query->whereDoesntHave('proofs');
    }

    /**
     * Scope to include leave requests with verified proofs
     */
    public function scopeWithVerifiedProofs($query)
    {
        return $query->whereHas('proofs', function ($query) {
            $query->where('is_verified', true);
        });
    }
}
