<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'position',
        'department_id',
        'photo_profile',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'photo_profile', // Hide S3 path, use accessor for URL
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'has_photo_profile',
        'photo_profile_url'
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the department that the user belongs to
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Get the attendances for the user
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * Get the task logs for the user
     */
    public function taskLogs(): HasMany
    {
        return $this->hasMany(TaskLog::class);
    }

    /**
     * Get the leave requests for the user
     */
    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    /**
     * Get the leave quotas for the user
     */
    public function leaveQuotas(): HasMany
    {
        return $this->hasMany(LeaveQuota::class);
    }

    /**
     * Get the working hours for the user
     */
    public function workingHours(): HasMany
    {
        return $this->hasMany(WorkingHour::class);
    }

    /**
     * Get the announcements created by the user
     */
    public function createdAnnouncements(): HasMany
    {
        return $this->hasMany(Announcement::class, 'created_by');
    }

    /**
     * Check if user is an admin
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Check if user has a photo profile
     *
     * @return bool
     */
    public function getHasPhotoProfileAttribute(): bool
    {
        return !is_null($this->photo_profile);
    }

    /**
     * Get photo profile URL (generates temporary URL for S3)
     *
     * @param int $expirationHours
     * @return string|null
     */
    public function getPhotoProfileUrl(int $expirationHours = 24): ?string
    {
        if (!$this->has_photo_profile) {
            return null;
        }

        try {
            // Check if file exists in S3
            if (!Storage::disk('s3')->exists($this->photo_profile)) {
                return null;
            }

            return Storage::disk('s3')->temporaryUrl(
                $this->photo_profile,
                now()->addHours($expirationHours)
            );
        } catch (\Exception $e) {
            \Log::error('Failed to generate photo profile URL for user', [
                'user_id' => $this->id,
                'photo_path' => $this->photo_profile,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get photo profile URL attribute for automatic appending
     *
     * @return string|null
     */
    public function getPhotoProfileUrlAttribute(): ?string
    {
        return $this->getPhotoProfileUrl();
    }

    /**
     * Check if photo profile exists in S3
     *
     * @return bool
     */
    public function photoProfileExists(): bool
    {
        if (!$this->has_photo_profile) {
            return false;
        }

        try {
            return Storage::disk('s3')->exists($this->photo_profile);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Delete photo profile from S3
     *
     * @return bool
     */
    public function deletePhotoProfile(): bool
    {
        if (!$this->has_photo_profile) {
            return true; // Nothing to delete
        }

        try {
            $deleted = Storage::disk('s3')->delete($this->photo_profile);

            if ($deleted) {
                $this->update(['photo_profile' => null]);
            }

            return $deleted;
        } catch (\Exception $e) {
            \Log::error('Failed to delete photo profile for user', [
                'user_id' => $this->id,
                'photo_path' => $this->photo_profile,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get the S3 path (for internal use)
     *
     * @return string|null
     */
    public function getPhotoProfilePath(): ?string
    {
        return $this->photo_profile;
    }

    /**
     * Scope to filter users with photo profiles
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithPhotoProfiles($query)
    {
        return $query->whereNotNull('photo_profile');
    }

    /**
     * Scope to filter users without photo profiles
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithoutPhotoProfiles($query)
    {
        return $query->whereNull('photo_profile');
    }
}
