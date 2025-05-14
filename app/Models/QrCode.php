<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QrCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'valid_until',
        'status'
    ];

    const UPDATED_AT = null; // Only uses created_at timestamp

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'valid_until' => 'datetime',
        'created_at' => 'datetime',
    ];

    /**
     * Scope a query to only include active QR codes.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Check if QR code is expired
     */
    public function isExpired(): bool
    {
        return now()->gt($this->valid_until);
    }
}
