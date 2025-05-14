<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'date',
        'description',
        'is_recurring'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'date' => 'date',
        'is_recurring' => 'boolean',
    ];

    /**
     * Scope a query to only include holidays for the given date
     */
    public function scopeForDate($query, $date)
    {
        $formattedDate = $date instanceof \DateTime ? $date : new \DateTime($date);
        
        return $query->where(function($query) use ($formattedDate) {
            // Exact date match
            $query->whereDate('date', $formattedDate);
            
            // Or recurring holiday with same month and day
            $query->orWhere(function($query) use ($formattedDate) {
                $query->where('is_recurring', true)
                      ->whereMonth('date', $formattedDate->format('m'))
                      ->whereDay('date', $formattedDate->format('d'));
            });
        });
    }

    /**
     * Scope a query to only include holidays for the current year
     */
    public function scopeCurrentYear($query)
    {
        $currentYear = now()->year;
        
        return $query->where(function($query) use ($currentYear) {
            // This year's holidays
            $query->whereYear('date', $currentYear);
            
            // Or recurring holidays from any year
            $query->orWhere('is_recurring', true);
        });
    }
}
