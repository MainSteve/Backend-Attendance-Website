<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * Display a listing of the users.
     */
    public function index()
    {
        $users = User::with('department')->get();
        return UserResource::collection($users);
    }

    /**
     * Store a newly created user in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'id' => 'required|numeric|max:255|unique:users',
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'role' => 'required|in:admin,employee',
            'position' => 'required|string|max:255',
            'department_id' => 'required|exists:departments,id',
            'photo_profile' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120' // 5MB max
        ]);

        $validated['password'] = Hash::make($validated['password']);

        // Handle photo profile upload if present
        $photoPath = null;
        if ($request->hasFile('photo_profile') && $request->file('photo_profile')->isValid()) {
            $photoPath = $this->handlePhotoUpload($request->file('photo_profile'), null);
            if ($photoPath) {
                $validated['photo_profile'] = $photoPath;
            }
        }

        $user = User::create($validated);
        $user->load('department');

        return new UserResource($user);
    }

    /**
     * Display the specified user.
     */
    public function show(User $user)
    {
        $user->load('department');
        return new UserResource($user);
    }

    /**
     * Update the specified user in storage.
     */
    public function update(Request $request, User $user)
    {
        // Check authorization
        $currentUser = Auth::user();
        if (!$currentUser->isAdmin() && $currentUser->id !== $user->id) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized: You can only update your own profile'
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => [
                'sometimes',
                'string',
                'email',
                'max:255',
                Rule::unique('users')->ignore($user->id),
            ],
            'password' => 'sometimes|string|min:8',
            'role' => 'sometimes|in:admin,employee',
            'position' => 'nullable|string|max:255',
            'department_id' => 'nullable|exists:departments,id',
            'photo_profile' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120' // 5MB max
        ]);

        // Handle photo profile upload if present
        if ($request->hasFile('photo_profile') && $request->file('photo_profile')->isValid()) {
            $newPhotoPath = $this->handlePhotoUpload($request->file('photo_profile'), $user->id);
            if ($newPhotoPath) {
                // Delete old photo if exists
                if ($user->photo_profile) {
                    try {
                        Storage::disk('s3')->delete($user->photo_profile);
                    } catch (\Exception $e) {
                        \Log::warning('Failed to delete old photo profile after successful upload', [
                            'user_id' => $user->id,
                            'old_photo_path' => $user->photo_profile,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
                $validated['photo_profile'] = $newPhotoPath;
            }
        }

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $user->update($validated);
        $user->load('department');

        return new UserResource($user);
    }

    /**
     * Remove the specified user from storage.
     */
    public function destroy(User $user)
    {
        // Delete photo profile before deleting user
        if ($user->photo_profile) {
            try {
                Storage::disk('s3')->delete($user->photo_profile);
            } catch (\Exception $e) {
                \Log::warning('Failed to delete photo profile during user deletion', [
                    'user_id' => $user->id,
                    'photo_path' => $user->photo_profile,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $user->delete();
        return response()->json(null, 204);
    }

    /**
     * Upload or update user photo profile
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $userId
     * @return \Illuminate\Http\JsonResponse
     */
    public function uploadPhotoProfile(Request $request, $userId)
    {
        $user = User::findOrFail($userId);

        // Check authorization
        $currentUser = Auth::user();
        if (!$currentUser->isAdmin() && $currentUser->id !== $user->id) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized: You can only update your own photo profile'
            ], 403);
        }

        $validator = \Validator::make($request->all(), [
            'photo_profile' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120' // 5MB max
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $file = $request->file('photo_profile');
            $newPhotoPath = $this->handlePhotoUpload($file, $user->id);

            if ($newPhotoPath) {
                // Delete old photo if exists
                if ($user->photo_profile) {
                    try {
                        Storage::disk('s3')->delete($user->photo_profile);
                    } catch (\Exception $e) {
                        \Log::warning('Failed to delete old photo profile', [
                            'user_id' => $user->id,
                            'old_photo_path' => $user->photo_profile,
                            'error' => $e->getMessage()
                        ]);
                    }
                }

                // Update user record
                $user->update(['photo_profile' => $newPhotoPath]);
                $user->load('department');

                return response()->json([
                    'status' => true,
                    'message' => 'Photo profile uploaded successfully',
                    'data' => new UserResource($user)
                ]);
            }
        } catch (\Exception $e) {
            \Log::error('Photo profile upload failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Photo upload failed. Please try again.',
                'error' => 'Unable to upload photo to cloud storage'
            ], 500);
        }
    }

    /**
     * Get user photo profile URL
     *
     * @param  int  $userId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPhotoProfile($userId)
    {
        $user = User::findOrFail($userId);

        if (!$user->photo_profile) {
            return response()->json([
                'status' => false,
                'message' => 'No photo profile found for this user'
            ], 404);
        }

        try {
            // Check if file exists in S3
            if (!Storage::disk('s3')->exists($user->photo_profile)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Photo profile file not found in storage'
                ], 404);
            }

            // Generate temporary URL (valid for 24 hours)
            $temporaryUrl = Storage::disk('s3')->temporaryUrl(
                $user->photo_profile,
                now()->addHours(24)
            );

            // Get file metadata
            $fileSize = Storage::disk('s3')->size($user->photo_profile);
            $mimeType = Storage::disk('s3')->mimeType($user->photo_profile);

            return response()->json([
                'status' => true,
                'message' => 'Photo profile URL generated successfully',
                'data' => [
                    'user_id' => $user->id,
                    'photo_profile_url' => $temporaryUrl,
                    'expires_at' => now()->addHours(24)->toDateTimeString(),
                    'file_info' => [
                        'size' => $fileSize,
                        'size_formatted' => $this->formatFileSize($fileSize),
                        'mime_type' => $mimeType
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to generate photo profile URL', [
                'user_id' => $user->id,
                'photo_path' => $user->photo_profile,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to generate photo profile URL'
            ], 500);
        }
    }

    /**
     * Delete user photo profile
     *
     * @param  int  $userId
     * @return \Illuminate\Http\JsonResponse
     */
    public function deletePhotoProfile($userId)
    {
        $user = User::findOrFail($userId);

        // Check authorization
        $currentUser = Auth::user();
        if (!$currentUser->isAdmin() && $currentUser->id !== $user->id) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized: You can only delete your own photo profile'
            ], 403);
        }

        if (!$user->photo_profile) {
            return response()->json([
                'status' => false,
                'message' => 'No photo profile found for this user'
            ], 404);
        }

        try {
            // Delete from S3
            $deleted = Storage::disk('s3')->delete($user->photo_profile);

            if ($deleted) {
                // Update user record
                $user->update(['photo_profile' => null]);

                return response()->json([
                    'status' => true,
                    'message' => 'Photo profile deleted successfully',
                    'data' => [
                        'user_id' => $user->id,
                        'deleted' => true
                    ]
                ]);
            } else {
                return response()->json([
                    'status' => false,
                    'message' => 'Failed to delete photo profile from storage'
                ], 500);
            }
        } catch (\Exception $e) {
            \Log::error('Failed to delete photo profile', [
                'user_id' => $user->id,
                'photo_path' => $user->photo_profile,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to delete photo profile',
                'error' => 'Unable to delete photo from cloud storage'
            ], 500);
        }
    }

    /**
     * Get current authenticated user's profile
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getProfile()
    {
        $user = Auth::user()->load('department');
        return new UserResource($user);
    }

    /**
     * Update current authenticated user's profile
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateProfile(Request $request)
    {
        $user = Auth::user();

        // Use the existing update method with the authenticated user
        $request->route()->setParameter('user', $user);
        return $this->update($request, $user);
    }

    /**
     * Helper method to upload photo profile to S3
     * RENAMED from uploadPhotoProfile to avoid confusion
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @param int|null $userId
     * @return string|null
     */
    private function handlePhotoUpload($file, $userId = null)
    {
        try {
            // Generate a unique filename
            $timestamp = now()->format('Y-m-d_H-i-s');
            $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $extension = $file->getClientOriginalExtension();
            $fileName = $userId ?
                "user_{$userId}_{$timestamp}_{$originalName}.{$extension}" :
                "user_new_{$timestamp}_{$originalName}.{$extension}";

            // Create the S3 path: profile-photos/year/month/filename
            $year = now()->format('Y');
            $month = now()->format('m');

            // Upload to S3 with private visibility for security
            $photoPath = $file->storeAs(
                "profile-photos/{$year}/{$month}",
                $fileName,
                ['disk' => 's3', 'visibility' => 'private']
            );

            return $photoPath;
        } catch (\Exception $e) {
            \Log::error('S3 Upload failed for photo profile', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Helper method to format file size
     *
     * @param int $bytes
     * @return string
     */
    private function formatFileSize($bytes)
    {
        if ($bytes == 0) return '0 B';

        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen($bytes) - 1) / 3);

        return sprintf("%.2f %s", $bytes / pow(1024, $factor), $units[$factor]);
    }
}
