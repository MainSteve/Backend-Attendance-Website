<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\TaskLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use App\Models\WorkingHour;
use App\Models\Holiday;
use App\Models\LeaveQuota;
use App\Models\LeaveRequest;

const VALIDATION_FAILED_MESSAGE = 'Validation failed';


class AttendanceController extends Controller
{
    /**
     * Display a listing of the attendance records for the authenticated employee.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        // Build the query
        if (Auth::user()->role === 'admin' && $request->has('user_id')) {
            $query = Attendance::with('taskLogs')
                ->where('user_id', $request->user_id);
        } else {
            $query = Attendance::with('taskLogs')
                ->where('user_id', Auth::id());
        }

        // Handle date filters
        if ($request->has('days')) {
            $days = $request->query('days');
            $query->whereDate('created_at', '>=', Carbon::now()->subDays($days));
        } elseif ($request->has('from_date') && $request->has('to_date')) {
            $validator = Validator::make($request->all(), [
                'from_date' => 'date',
                'to_date' => 'date|after_or_equal:from_date',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => VALIDATION_FAILED_MESSAGE,
                    'errors' => $validator->errors()
                ], 422);
            }

            $query->whereDate('created_at', '>=', $request->from_date)
                ->whereDate('created_at', '<=', $request->to_date);
        } elseif ($request->has('date')) {
            $validator = Validator::make($request->all(), [
                'date' => 'date',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => VALIDATION_FAILED_MESSAGE,
                    'errors' => $validator->errors()
                ], 422);
            }

            $query->whereDate('created_at', $request->date);
        } else {
            // Default to 30 days if no date filters specified
            $query->whereDate('created_at', '>=', Carbon::now()->subDays(30));
        }

        // Handle clock type filter
        if ($request->has('clock_type') && in_array($request->clock_type, ['in', 'out'])) {
            $query->where('clock_type', $request->clock_type);
        }

        // Handle method filter
        if ($request->has('method') && in_array($request->method, ['manual', 'qr_code'])) {
            $query->where('method', $request->method);
        }

        // Handle location filter
        if ($request->has('location')) {
            $query->where('location', 'like', '%' . $request->location . '%');
        }

        // Handle sort (default is newest first)
        $sortField = $request->query('sort_by', 'created_at');
        $sortDirection = $request->query('sort_direction', 'desc');

        // Validate sort field is allowed
        $allowedSortFields = ['created_at', 'clock_type', 'method', 'location'];
        if (!in_array($sortField, $allowedSortFields)) {
            $sortField = 'created_at';
        }

        // Validate sort direction
        if (!in_array($sortDirection, ['asc', 'desc'])) {
            $sortDirection = 'desc';
        }

        $query->orderBy($sortField, $sortDirection);

        // Handle pagination
        $perPage = $request->query('per_page', 15);
        // Validate per_page is reasonable
        if ($perPage < 1 || $perPage > 100) {
            $perPage = 15;
        }

        $attendances = $query->paginate($perPage);

        // Add query parameters to pagination links
        $attendances->appends($request->query());

        return response()->json([
            'status' => true,
            'message' => 'Attendance records retrieved successfully',
            'data' => $attendances
        ]);
    }

    /**
     * Store a newly created attendance record.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'clock_type' => 'required|in:in,out',
            'location' => 'nullable|string|max:255',
            'method' => 'required|in:manual,qr_code',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => VALIDATION_FAILED_MESSAGE,
                'errors' => $validator->errors()
            ], 422);
        }

        $userId = Auth::id();
        $today = Carbon::today();

        // Check if user already has a record of the same clock_type today
        if ($request->clock_type === 'in') {
            $existingClockIn = Attendance::where('user_id', $userId)
                ->where('clock_type', 'in')
                ->whereDate('created_at', $today)
                ->exists();

            if ($existingClockIn) {
                return response()->json([
                    'status' => false,
                    'message' => 'You have already clocked in today',
                ], 422);
            }
        } elseif ($request->clock_type === 'out') {
            // Check if user has clocked in today before allowing clock out
            $existingClockIn = Attendance::where('user_id', $userId)
                ->where('clock_type', 'in')
                ->whereDate('created_at', $today)
                ->exists();

            if (!$existingClockIn) {
                return response()->json([
                    'status' => false,
                    'message' => 'You must clock in before you can clock out',
                ], 422);
            }

            // Check if user has already clocked out today
            $existingClockOut = Attendance::where('user_id', $userId)
                ->where('clock_type', 'out')
                ->whereDate('created_at', $today)
                ->exists();

            if ($existingClockOut) {
                return response()->json([
                    'status' => false,
                    'message' => 'You have already clocked out today',
                ], 422);
            }
        }

        // Create the attendance record
        $attendance = Attendance::create([
            'user_id' => Auth::id(),
            'clock_type' => $request->clock_type,
            'location' => $request->location ?? 'Remote',
            'method' => $request->method
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Attendance recorded successfully',
            'data' => $attendance
        ], 201);
    }

    /**
     * Get the latest attendance record for the authenticated employee.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function latest()
    {
        $user_id = Auth::id();

        $latestAttendance = Attendance::with('taskLogs')
            ->where('user_id', $user_id)
            ->latest()
            ->first();

        if (!$latestAttendance) {
            return response()->json([
                'status' => false,
                'message' => 'No attendance records found'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Latest attendance record retrieved successfully',
            'data' => $latestAttendance
        ]);
    }

    /**
     * Display the specified attendance record.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        // Check if user is admin
        $isAdmin = Auth::user()->role === 'admin';

        // Build query based on user role
        $query = Attendance::with('taskLogs')->where('id', $id);
        if (!$isAdmin) {
            // If not admin, restrict to own records only
            $query->where('user_id', Auth::id());
        }

        $attendance = $query->first();

        if (!$attendance) {
            return response()->json([
                'status' => false,
                'message' => $isAdmin ? 'Attendance record not found' : 'Attendance record not found or unauthorized'
            ], 404);
        }

        // Process task logs to include photo URLs
        $taskLogs = $this->processTaskLogsWithPhotos($attendance->taskLogs);

        // Add processed task logs to the response
        $attendanceData = $attendance->toArray();
        $attendanceData['task_logs'] = $taskLogs;
        $attendanceData['task_logs_count'] = count($taskLogs);
        $attendanceData['photos_count'] = count(array_filter($taskLogs, function ($log) {
            return $log['has_photo'] && !is_null($log['photo_url']);
        }));

        return response()->json([
            'status' => true,
            'message' => 'Attendance record retrieved successfully',
            'data' => $attendanceData
        ]);
    }

    /**
     * Get today's attendance records for the authenticated employee.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function today()
    {
        $user_id = Auth::id();

        $todayAttendances = Attendance::with('taskLogs')
            ->where('user_id', $user_id)
            ->whereDate('created_at', Carbon::today())
            ->orderBy('created_at', 'asc')
            ->get();

        $clockIn = $todayAttendances->where('clock_type', 'in')->first();
        $clockOut = $todayAttendances->where('clock_type', 'out')->first();

        $workDuration = null;
        if ($clockIn && $clockOut) {
            $inTime = Carbon::parse($clockIn->created_at);
            $outTime = Carbon::parse($clockOut->created_at);

            $totalMinutes = abs($outTime->diffInMinutes($inTime));

            $workDuration = [
                'hours' => floor($totalMinutes / 60),
                'minutes' => $totalMinutes % 60,
                'total_minutes' => $totalMinutes,
                'hours_formatted' => sprintf("%d:%02d", floor($totalMinutes / 60), $totalMinutes % 60)
            ];
        }

        // Get all task logs for today with photo URLs
        $allTaskLogs = collect();
        foreach ($todayAttendances as $attendance) {
            $allTaskLogs = $allTaskLogs->merge($attendance->taskLogs);
        }

        $taskLogs = $this->processTaskLogsWithPhotos($allTaskLogs);

        return response()->json([
            'status' => true,
            'message' => 'Today\'s attendance records retrieved successfully',
            'data' => [
                'attendances' => $todayAttendances,
                'work_duration' => $workDuration,
                'task_logs' => $taskLogs,
                'task_logs_count' => count($taskLogs),
                'photos_count' => count(array_filter($taskLogs, function ($log) {
                    return $log['has_photo'] && !is_null($log['photo_url']);
                }))
            ]
        ]);
    }

    /**
     * Add a task log to an attendance record.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Attendance  $attendance
     * @return \Illuminate\Http\JsonResponse
     */
    public function addTaskLog(Request $request, Attendance $attendance)
    {
        // Check if attendance belongs to authenticated user
        if ($attendance->user_id !== Auth::id()) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized: This attendance record does not belong to you'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'description' => 'required|string|max:1000',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120' // 5MB max, added webp support
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => VALIDATION_FAILED_MESSAGE,
                'errors' => $validator->errors()
            ], 422);
        }

        // Handle photo upload to S3 if present
        $photoUrl = null;
        $photoPath = null;
        $temporaryUrl = null;

        if ($request->hasFile('photo') && $request->file('photo')->isValid()) {
            try {
                $file = $request->file('photo');
                $userId = Auth::id();
                $attendanceId = $attendance->id;

                // Generate a unique filename with timestamp and user info
                $timestamp = now()->format('Y-m-d_H-i-s');
                $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                $extension = $file->getClientOriginalExtension();
                $fileName = "user_{$userId}_attendance_{$attendanceId}_{$timestamp}_{$originalName}.{$extension}";

                // Create the S3 path: task-logs/year/month/filename
                $year = now()->format('Y');
                $month = now()->format('m');

                // Upload to S3 with private visibility for security
                $photoPath = $file->storeAs(
                    "task-logs/{$year}/{$month}",
                    $fileName,
                    ['disk' => 's3', 'visibility' => 'private']
                );

                if ($photoPath) {
                    // Generate a temporary URL (valid for 24 hours) for immediate access
                    $temporaryUrl = Storage::disk('s3')->temporaryUrl(
                        $photoPath,
                        now()->addHours(24)
                    );
                }
            } catch (\Exception $e) {
                // Log the error for debugging
                \Log::error('S3 Upload failed for task log photo', [
                    'user_id' => Auth::id(),
                    'attendance_id' => $attendance->id,
                    'error' => $e->getMessage()
                ]);

                return response()->json([
                    'status' => false,
                    'message' => 'Photo upload failed. Please try again.',
                    'error' => 'Unable to upload photo to cloud storage'
                ], 500);
            }
        }

        try {
            // Create the task log - store S3 path in photo_url column
            $taskLog = TaskLog::create([
                'user_id' => Auth::id(),
                'attendance_id' => $attendance->id,
                'description' => $request->description,
                'photo_url' => $photoPath // Store S3 path instead of URL
            ]);

            // Prepare response data
            $responseData = [
                'id' => $taskLog->id,
                'user_id' => $taskLog->user_id,
                'attendance_id' => $taskLog->attendance_id,
                'description' => $taskLog->description,
                'photo_url' => $temporaryUrl, // Return temporary URL for immediate access
                'created_at' => $taskLog->created_at,
                'has_photo' => !is_null($photoPath)
            ];

            return response()->json([
                'status' => true,
                'message' => 'Task log added successfully',
                'data' => $responseData
            ], 201);
        } catch (\Exception $e) {
            // If task log creation fails and we uploaded a photo, clean up S3
            if ($photoPath) {
                try {
                    Storage::disk('s3')->delete($photoPath);
                } catch (\Exception $cleanupError) {
                    \Log::error('Failed to cleanup S3 file after task log creation failure', [
                        'photo_path' => $photoPath,
                        'error' => $cleanupError->getMessage()
                    ]);
                }
            }

            \Log::error('Task log creation failed', [
                'user_id' => Auth::id(),
                'attendance_id' => $attendance->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to create task log. Please try again.',
                'error' => 'Database operation failed'
            ], 500);
        }
    }

    public function getTaskLogPhoto($taskLogId)
    {
        $currentUser = Auth::user();
        $isAdmin = $currentUser->role === 'admin';

        // Build query based on user role
        $query = TaskLog::where('id', $taskLogId);

        // If not admin, restrict to own task logs only
        if (!$isAdmin) {
            $query->where('user_id', Auth::id());
        }

        $taskLog = $query->first();

        if (!$taskLog) {
            return response()->json([
                'status' => false,
                'message' => $isAdmin ? 'Task log not found' : 'Task log not found or unauthorized'
            ], 404);
        }

        if (!$taskLog->photo_url) {
            return response()->json([
                'status' => false,
                'message' => 'No photo found for this task log'
            ], 404);
        }

        try {
            // Check if file exists in S3
            if (!Storage::disk('s3')->exists($taskLog->photo_url)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Photo file not found in storage'
                ], 404);
            }

            // Generate temporary URL (valid for 24 hours)
            $temporaryUrl = Storage::disk('s3')->temporaryUrl(
                $taskLog->photo_url,
                now()->addHours(24)
            );

            // Get file metadata
            $fileSize = Storage::disk('s3')->size($taskLog->photo_url);
            $mimeType = Storage::disk('s3')->mimeType($taskLog->photo_url);

            $responseData = [
                'task_log_id' => $taskLog->id,
                'attendance_id' => $taskLog->attendance_id,
                'photo_url' => $temporaryUrl,
                'expires_at' => now()->addHours(24)->toDateTimeString(),
                'file_info' => [
                    'size' => $fileSize,
                    'size_formatted' => $this->formatFileSize($fileSize),
                    'mime_type' => $mimeType
                ]
            ];

            // Include user info for admin
            if ($isAdmin) {
                $responseData['user'] = [
                    'id' => $taskLog->user_id,
                    'name' => $taskLog->user->name ?? 'Unknown'
                ];
            }

            return response()->json([
                'status' => true,
                'message' => 'Photo URL generated successfully',
                'data' => $responseData
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to generate photo URL for task log', [
                'task_log_id' => $taskLog->id,
                'photo_path' => $taskLog->photo_url,
                'requested_by' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to generate photo URL'
            ], 500);
        }
    }

    /**
     * Get all task log photos for a specific attendance record
     * Employees can only see their own, Admins can see any
     *
     * @param  int  $attendanceId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAttendanceTaskLogPhotos($attendanceId)
    {
        $currentUser = Auth::user();
        $isAdmin = $currentUser->role === 'admin';

        // First, get the attendance record
        $attendanceQuery = Attendance::where('id', $attendanceId);

        // If not admin, restrict to own attendance only
        if (!$isAdmin) {
            $attendanceQuery->where('user_id', Auth::id());
        }

        $attendance = $attendanceQuery->first();

        if (!$attendance) {
            return response()->json([
                'status' => false,
                'message' => $isAdmin ? 'Attendance record not found' : 'Attendance record not found or unauthorized'
            ], 404);
        }

        // Get task logs with photos for this attendance
        $taskLogs = TaskLog::where('attendance_id', $attendanceId)
            ->whereNotNull('photo_url')
            ->with('user:id,name') // Include user info for admin
            ->orderBy('created_at', 'desc')
            ->get();

        if ($taskLogs->isEmpty()) {
            return response()->json([
                'status' => true,
                'message' => 'No photos found for this attendance record',
                'data' => [
                    'attendance_id' => $attendanceId,
                    'photos' => []
                ]
            ]);
        }

        $photos = [];
        foreach ($taskLogs as $taskLog) {
            try {
                // Check if file exists in S3
                if (Storage::disk('s3')->exists($taskLog->photo_url)) {
                    // Generate temporary URL
                    $temporaryUrl = Storage::disk('s3')->temporaryUrl(
                        $taskLog->photo_url,
                        now()->addHours(24)
                    );

                    $photoData = [
                        'task_log_id' => $taskLog->id,
                        'description' => $taskLog->description,
                        'photo_url' => $temporaryUrl,
                        'created_at' => $taskLog->created_at,
                        'expires_at' => now()->addHours(24)->toDateTimeString()
                    ];

                    // Include user info for admin
                    if ($isAdmin && $taskLog->user) {
                        $photoData['user'] = [
                            'id' => $taskLog->user->id,
                            'name' => $taskLog->user->name
                        ];
                    }

                    $photos[] = $photoData;
                }
            } catch (\Exception $e) {
                // Log error but continue with other photos
                \Log::error('Failed to generate photo URL for task log', [
                    'task_log_id' => $taskLog->id,
                    'photo_path' => $taskLog->photo_url,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Photos retrieved successfully',
            'data' => [
                'attendance_id' => $attendanceId,
                'attendance_date' => $attendance->created_at->toDateString(),
                'attendance_user' => $isAdmin ? [
                    'id' => $attendance->user_id,
                    'name' => $attendance->user->name ?? 'Unknown'
                ] : null,
                'photos_count' => count($photos),
                'photos' => $photos
            ]
        ]);
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

    /**
     * Update a task log (description and/or photo)
     * Employees can only update their own task logs, Admins can update any
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $taskLogId
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateTaskLog(Request $request, $taskLogId)
    {
        $currentUser = Auth::user();
        $isAdmin = $currentUser->role === 'admin';

        // Build query based on user role
        $query = TaskLog::where('id', $taskLogId);

        // If not admin, restrict to own task logs only
        if (!$isAdmin) {
            $query->where('user_id', Auth::id());
        }

        $taskLog = $query->first();

        if (!$taskLog) {
            return response()->json([
                'status' => false,
                'message' => $isAdmin ? 'Task log not found' : 'Task log not found or unauthorized'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'description' => 'nullable|string|max:1000',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120' // 5MB max
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => VALIDATION_FAILED_MESSAGE,
                'errors' => $validator->errors()
            ], 422);
        }

        // Check if at least one field is being updated
        if (!$request->has('description') && !$request->hasFile('photo')) {
            return response()->json([
                'status' => false,
                'message' => 'At least one field (description or photo) must be provided for update'
            ], 422);
        }

        $updateData = [];
        $newPhotoPath = null;
        $temporaryUrl = null;
        $oldPhotoPath = $taskLog->photo_url;

        // Handle photo upload if present
        if ($request->hasFile('photo') && $request->file('photo')->isValid()) {
            try {
                $file = $request->file('photo');
                $userId = $taskLog->user_id;
                $attendanceId = $taskLog->attendance_id;

                // Generate a unique filename with timestamp and user info
                $timestamp = now()->format('Y-m-d_H-i-s');
                $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                $extension = $file->getClientOriginalExtension();
                $fileName = "user_{$userId}_attendance_{$attendanceId}_{$timestamp}_{$originalName}.{$extension}";

                // Create the S3 path: task-logs/year/month/filename
                $year = now()->format('Y');
                $month = now()->format('m');

                // Upload new photo to S3 with private visibility
                $newPhotoPath = $file->storeAs(
                    "task-logs/{$year}/{$month}",
                    $fileName,
                    ['disk' => 's3', 'visibility' => 'private']
                );

                if ($newPhotoPath) {
                    $updateData['photo_url'] = $newPhotoPath;

                    // Generate temporary URL for response
                    $temporaryUrl = Storage::disk('s3')->temporaryUrl(
                        $newPhotoPath,
                        now()->addHours(24)
                    );
                }
            } catch (\Exception $e) {
                \Log::error('S3 Upload failed for task log photo update', [
                    'task_log_id' => $taskLogId,
                    'user_id' => Auth::id(),
                    'error' => $e->getMessage()
                ]);

                return response()->json([
                    'status' => false,
                    'message' => 'Photo upload failed. Please try again.',
                    'error' => 'Unable to upload photo to cloud storage'
                ], 500);
            }
        }

        // Handle description update
        if ($request->has('description')) {
            $updateData['description'] = $request->description;
        }

        try {
            // Update the task log
            $taskLog->update($updateData);

            // If new photo was uploaded successfully, delete the old photo
            if ($newPhotoPath && $oldPhotoPath) {
                try {
                    Storage::disk('s3')->delete($oldPhotoPath);
                } catch (\Exception $e) {
                    // Log warning but don't fail the update
                    \Log::warning('Failed to delete old task log photo after successful update', [
                        'task_log_id' => $taskLogId,
                        'old_photo_path' => $oldPhotoPath,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Prepare response data
            $responseData = [
                'id' => $taskLog->id,
                'user_id' => $taskLog->user_id,
                'attendance_id' => $taskLog->attendance_id,
                'description' => $taskLog->description,
                'created_at' => $taskLog->created_at,
                'updated_fields' => array_keys($updateData),
                'has_photo' => !is_null($taskLog->photo_url)
            ];

            // Add photo info to response
            if ($taskLog->photo_url) {
                // Use new temporary URL if photo was just uploaded, otherwise generate one
                if ($temporaryUrl) {
                    $responseData['photo_url'] = $temporaryUrl;
                } else {
                    try {
                        if (Storage::disk('s3')->exists($taskLog->photo_url)) {
                            $responseData['photo_url'] = Storage::disk('s3')->temporaryUrl(
                                $taskLog->photo_url,
                                now()->addHours(24)
                            );
                        }
                    } catch (\Exception $e) {
                        \Log::error('Failed to generate photo URL after task log update', [
                            'task_log_id' => $taskLogId,
                            'error' => $e->getMessage()
                        ]);
                    }
                }

                $responseData['photo_expires_at'] = now()->addHours(24)->toDateTimeString();
            } else {
                $responseData['photo_url'] = null;
            }

            // Include user info for admin
            if ($isAdmin) {
                $responseData['user'] = [
                    'id' => $taskLog->user_id,
                    'name' => $taskLog->user->name ?? 'Unknown'
                ];
            }

            return response()->json([
                'status' => true,
                'message' => 'Task log updated successfully',
                'data' => $responseData
            ]);
        } catch (\Exception $e) {
            // If database update fails and we uploaded a new photo, clean it up
            if ($newPhotoPath) {
                try {
                    Storage::disk('s3')->delete($newPhotoPath);
                } catch (\Exception $cleanupError) {
                    \Log::error('Failed to cleanup new S3 file after task log update failure', [
                        'task_log_id' => $taskLogId,
                        'new_photo_path' => $newPhotoPath,
                        'error' => $cleanupError->getMessage()
                    ]);
                }
            }

            \Log::error('Task log update failed', [
                'task_log_id' => $taskLogId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to update task log. Please try again.',
                'error' => 'Database operation failed'
            ], 500);
        }
    }

    /**
     * Delete a task log and its associated photo
     * Employees can only delete their own task logs, Admins can delete any
     *
     * @param  int  $taskLogId
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteTaskLog($taskLogId)
    {
        $currentUser = Auth::user();
        $isAdmin = $currentUser->role === 'admin';

        // Build query based on user role
        $query = TaskLog::where('id', $taskLogId);

        // If not admin, restrict to own task logs only
        if (!$isAdmin) {
            $query->where('user_id', Auth::id());
        }

        $taskLog = $query->first();

        if (!$taskLog) {
            return response()->json([
                'status' => false,
                'message' => $isAdmin ? 'Task log not found' : 'Task log not found or unauthorized'
            ], 404);
        }

        $photoPath = $taskLog->photo_url;

        try {
            // Delete the task log from database
            $taskLog->delete();

            // Delete the photo from S3 if exists
            if ($photoPath) {
                try {
                    Storage::disk('s3')->delete($photoPath);
                } catch (\Exception $e) {
                    // Log warning but don't fail the delete operation
                    \Log::warning('Failed to delete task log photo from S3 after database deletion', [
                        'task_log_id' => $taskLogId,
                        'photo_path' => $photoPath,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Task log deleted successfully',
                'data' => [
                    'deleted_task_log_id' => $taskLogId,
                    'had_photo' => !is_null($photoPath),
                    'deleted_by' => Auth::id()
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('Task log deletion failed', [
                'task_log_id' => $taskLogId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to delete task log. Please try again.',
                'error' => 'Database operation failed'
            ], 500);
        }
    }

    /**
     * Get attendance report for a date range.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    private function groupAttendanceRecordsByDate($attendances)
    {
        return $attendances->groupBy(function ($item) {
            return Carbon::parse($item->created_at)->format('Y-m-d');
        });
    }

    /**
     * Get attendance report for a date range, taking holidays and working hours into account.
     * Admins can view reports for any user by providing a user_id parameter.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function report(Request $request)
    {
        $validationRules = [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'include_task_logs' => 'nullable|boolean',
            'filter_clock_type' => 'nullable|in:in,out',
            'filter_method' => 'nullable|in:manual,qr_code',
            'filter_location' => 'nullable|string',
        ];

        // Add user_id validation if the current user is an admin
        $currentUser = Auth::user();
        $isAdmin = $currentUser->role === 'admin';

        if ($isAdmin) {
            $validationRules['user_id'] = 'nullable|exists:users,id';
        }

        $validator = Validator::make($request->all(), $validationRules);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => VALIDATION_FAILED_MESSAGE,
                'errors' => $validator->errors()
            ], 422);
        }

        // Determine which user's attendance to report on
        $user_id = $currentUser->id;
        $reportUser = $currentUser;

        // If admin is requesting another user's report
        if ($isAdmin && $request->has('user_id')) {
            $user_id = $request->user_id;
            $reportUser = \App\Models\User::find($user_id);

            if (!$reportUser) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found'
                ], 404);
            }
        }

        $includeTaskLogs = $request->input('include_task_logs', true);
        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);

        // Get the user's attendance records
        $attendances = $this->getAttendanceRecordsForUser($request, $user_id);
        $groupedAttendances = $this->groupAttendanceRecordsByDate($attendances);
        $report = $this->processDailyStats($groupedAttendances, $includeTaskLogs);

        // Get holidays in this date range
        $holidays = Holiday::where(function ($query) use ($startDate, $endDate) {
            // Regular holidays in the date range
            $query->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()]);

            // Or recurring holidays that fall within this date range
            $query->orWhere(function ($q) use ($startDate, $endDate) {
                $q->where('is_recurring', true)
                    ->where(function ($dateQ) use ($startDate, $endDate) {
                        // Loop through each date in range to check for recurring holidays
                        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
                            $dateQ->orWhere(function ($dayMonthQ) use ($date) {
                                $dayMonthQ->whereDay('date', $date->day)
                                    ->whereMonth('date', $date->month);
                            });
                        }
                    });
            });
        })->get();

        // Map holidays to their dates for easy lookup
        $holidayDates = [];
        foreach ($holidays as $holiday) {
            if ($holiday->is_recurring) {
                // For recurring holidays, add all instances in the date range
                for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
                    if (
                        $date->day == Carbon::parse($holiday->date)->day &&
                        $date->month == Carbon::parse($holiday->date)->month
                    ) {
                        $formattedDate = $date->toDateString();
                        $holidayDates[$formattedDate] = $holiday;
                    }
                }
            } else {
                // For regular holidays
                $formattedDate = Carbon::parse($holiday->date)->toDateString();
                $holidayDates[$formattedDate] = $holiday;
            }
        }

        // Get leave requests in this date range
        $leaveRequests = LeaveRequest::where('user_id', $user_id)
            ->where('status', 'approved')
            ->where(function ($query) use ($startDate, $endDate) {
                // Requests that start within the range
                $query->whereBetween('start_date', [$startDate->toDateString(), $endDate->toDateString()])
                    // Or requests that end within the range
                    ->orWhereBetween('end_date', [$startDate->toDateString(), $endDate->toDateString()])
                    // Or requests that span the entire range
                    ->orWhere(function ($q) use ($startDate, $endDate) {
                        $q->where('start_date', '<=', $startDate->toDateString())
                            ->where('end_date', '>=', $endDate->toDateString());
                    });
            })->get();

        // Map leave requests to their dates for easy lookup
        $leaveDates = [];
        foreach ($leaveRequests as $leave) {
            $leaveStartDate = Carbon::parse($leave->start_date);
            $leaveEndDate = Carbon::parse($leave->end_date);

            for ($date = $leaveStartDate->copy(); $date->lte($leaveEndDate); $date->addDay()) {
                $formattedDate = $date->toDateString();
                $leaveDates[$formattedDate] = $leave;
            }
        }

        // Get working hours for the user
        $workingHours = WorkingHour::where('user_id', $user_id)->get()->keyBy('day_of_week');

        // Calculate total days in range
        $totalDaysInRange = $endDate->diffInDays($startDate) + 1;

        // Initialize counters
        $workDays = 0;
        $workingDaysPresentCount = 0;
        $workingDaysAbsentCount = 0;
        $workingDaysLeaveCount = 0;
        $holidayCount = count(array_unique(array_keys($holidayDates)));
        $weekends = 0;
        $weekdays = 0;
        $totalScheduledMinutes = 0;
        $totalActualMinutes = 0;
        $leaveCountByType = [
            'izin' => 0,
            'sakit' => 0,
            'cuti' => 0
        ];

        // Enhanced daily records with working hours and holiday info
        $enhancedReport = [];

        // Process each day in the date range
        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
            $dateStr = $date->toDateString();
            $dayOfWeek = strtolower($date->format('l'));

            // Check if this is a holiday
            $isHoliday = isset($holidayDates[$dateStr]);
            $holidayInfo = $isHoliday ? $holidayDates[$dateStr] : null;

            // Check if this is a leave day
            $isLeave = isset($leaveDates[$dateStr]);
            $leaveInfo = $isLeave ? $leaveDates[$dateStr] : null;

            // Check if this is a work day (has working hours and not a holiday and not on leave)
            $hasWorkingHours = isset($workingHours[$dayOfWeek]);
            $isWorkDay = $hasWorkingHours && !$isHoliday && !$isLeave; // Updated to consider leave days as non-work days

            // Update counters
            if ($date->isWeekday()) {
                $weekdays++;
            } else {
                $weekends++;
            }

            if ($hasWorkingHours && !$isHoliday) {
                // Count as a work day if it has working hours and is not a holiday
                // (regardless of leave status)
                $workDays++;

                // Calculate scheduled hours for this day
                $scheduledStartTime = isset($workingHours[$dayOfWeek]) ?
                    Carbon::parse($workingHours[$dayOfWeek]->start_time) : null;
                $scheduledEndTime = isset($workingHours[$dayOfWeek]) ?
                    Carbon::parse($workingHours[$dayOfWeek]->end_time) : null;

                // Only calculate scheduled minutes if not on leave
                if (!$isLeave) {
                    // FIX: Ensure we calculate the correct positive duration
                    $scheduledMinutes = 0;
                    if ($scheduledStartTime && $scheduledEndTime) {
                        // Create today's date with these times for proper comparison
                        $startDateTime = Carbon::today()->setTimeFromTimeString($scheduledStartTime->format('H:i:s'));
                        $endDateTime = Carbon::today()->setTimeFromTimeString($scheduledEndTime->format('H:i:s'));

                        // Handle cases where end time might be on the next day
                        if ($endDateTime->lt($startDateTime)) {
                            $endDateTime->addDay();
                        }

                        $scheduledMinutes = $startDateTime->diffInMinutes($endDateTime);
                        $totalScheduledMinutes += $scheduledMinutes;
                    }
                }

                // Count leave days by type
                if ($isLeave) {
                    $workingDaysLeaveCount++;
                    $leaveType = $leaveInfo->type;
                    if (isset($leaveCountByType[$leaveType])) {
                        $leaveCountByType[$leaveType]++;
                    }
                }
            }

            // Find the existing report entry for this date or create a new one
            $dayReport = null;
            foreach ($report as $r) {
                if ($r['date'] == $dateStr) {
                    $dayReport = $r;
                    break;
                }
            }

            if ($dayReport) {
                // Day exists in attendance record
                if ($isWorkDay) {
                    $workingDaysPresentCount++;

                    // FIX: Ensure we're adding positive minutes and using integer values
                    $actualMinutes = isset($dayReport['total_minutes']) ? (int)abs($dayReport['total_minutes']) : 0;
                    $totalActualMinutes += $actualMinutes;

                    // FIX: Correct the total_minutes and total_hours in the day report
                    if (isset($dayReport['total_minutes'])) {
                        $dayReport['total_minutes'] = $actualMinutes;
                        $dayReport['total_hours'] = (int)floor($actualMinutes / 60);
                        $dayReport['hours_formatted'] = sprintf(
                            "%d:%02d",
                            floor($actualMinutes / 60),
                            $actualMinutes % 60
                        );
                    }
                }

                // Add working hours, holiday and leave info to the report
                $dayReport['is_holiday'] = $isHoliday;
                $dayReport['holiday_info'] = $isHoliday ? [
                    'id' => $holidayInfo->id,
                    'name' => $holidayInfo->name,
                    'description' => $holidayInfo->description,
                    'is_recurring' => $holidayInfo->is_recurring
                ] : null;

                $dayReport['is_leave'] = $isLeave;
                $dayReport['leave_info'] = $isLeave ? [
                    'id' => $leaveInfo->id,
                    'type' => $leaveInfo->type,
                    'reason' => $leaveInfo->reason,
                    'start_date' => $leaveInfo->start_date,
                    'end_date' => $leaveInfo->end_date,
                    'duration' => $leaveInfo->getDurationAttribute()
                ] : null;

                // FIX: Don't include scheduled hours for leave days
                if (!$isLeave && $hasWorkingHours && !$isHoliday) {
                    // Calculate correct scheduled hours
                    $scheduledHoursFormatted = null;
                    if ($scheduledStartTime && $scheduledEndTime) {
                        $scheduledMinutes = $startDateTime->diffInMinutes($endDateTime);
                        $scheduledHoursFormatted = sprintf(
                            "%d:%02d",
                            floor($scheduledMinutes / 60),
                            $scheduledMinutes % 60
                        );
                    }

                    $dayReport['scheduled_hours'] = [
                        'start_time' => $scheduledStartTime ? $scheduledStartTime->format('H:i:s') : null,
                        'end_time' => $scheduledEndTime ? $scheduledEndTime->format('H:i:s') : null,
                        'duration_minutes' => (int)$scheduledMinutes, // Cast to integer to avoid decimal values
                        'hours_formatted' => $scheduledHoursFormatted
                    ];
                } else {
                    // Set scheduled_hours to null for leave days
                    $dayReport['scheduled_hours'] = null;
                }

                $enhancedReport[] = $dayReport;
            } else {
                // Day doesn't exist in attendance records
                if ($isWorkDay) {
                    $workingDaysAbsentCount++;
                }

                // FIX: Calculate correct scheduled hours for new entry
                $scheduledHoursFormatted = null;
                $scheduledHours = null;

                // Only include scheduled hours if not on leave
                if (!$isLeave && $hasWorkingHours && !$isHoliday) {
                    $scheduledStartTime = isset($workingHours[$dayOfWeek]) ?
                        Carbon::parse($workingHours[$dayOfWeek]->start_time) : null;
                    $scheduledEndTime = isset($workingHours[$dayOfWeek]) ?
                        Carbon::parse($workingHours[$dayOfWeek]->end_time) : null;

                    if ($scheduledStartTime && $scheduledEndTime) {
                        // Create today's date with these times for proper comparison
                        $startDateTime = Carbon::today()->setTimeFromTimeString($scheduledStartTime->format('H:i:s'));
                        $endDateTime = Carbon::today()->setTimeFromTimeString($scheduledEndTime->format('H:i:s'));

                        // Handle cases where end time might be on the next day
                        if ($endDateTime->lt($startDateTime)) {
                            $endDateTime->addDay();
                        }

                        $scheduledMinutes = $startDateTime->diffInMinutes($endDateTime);
                        $scheduledHoursFormatted = sprintf(
                            "%d:%02d",
                            floor($scheduledMinutes / 60),
                            $scheduledMinutes % 60
                        );

                        $scheduledHours = [
                            'start_time' => $scheduledStartTime->format('H:i:s'),
                            'end_time' => $scheduledEndTime->format('H:i:s'),
                            'duration_minutes' => (int)$scheduledMinutes,
                            'hours_formatted' => $scheduledHoursFormatted
                        ];
                    }
                }

                // Create a new report entry for this day
                $enhancedReport[] = [
                    'date' => $dateStr,
                    'day_of_week' => $date->format('l'),
                    'clock_in' => null,
                    'clock_out' => null,
                    'clock_in_method' => null,
                    'clock_out_method' => null,
                    'location' => null,
                    'total_hours' => 0,
                    'total_minutes' => 0,
                    'hours_formatted' => "0:00",
                    'is_holiday' => $isHoliday,
                    'holiday_info' => $isHoliday ? [
                        'id' => $holidayInfo->id,
                        'name' => $holidayInfo->name,
                        'description' => $holidayInfo->description,
                        'is_recurring' => $holidayInfo->is_recurring
                    ] : null,
                    'is_leave' => $isLeave,
                    'leave_info' => $isLeave ? [
                        'id' => $leaveInfo->id,
                        'type' => $leaveInfo->type,
                        'reason' => $leaveInfo->reason,
                        'start_date' => $leaveInfo->start_date,
                        'end_date' => $leaveInfo->end_date,
                        'duration' => $leaveInfo->getDurationAttribute()
                    ] : null,
                    'scheduled_hours' => $scheduledHours, // Will be null for leave days
                    'task_logs' => [],
                    'task_logs_count' => 0
                ];
            }
        }

        // Sort the enhanced report by date
        usort($enhancedReport, function ($a, $b) {
            return strcmp($a['date'], $b['date']);
        });

        // Calculate attendance percentage (for working days only)
        $attendanceRate = $workDays > 0 ? ($workingDaysPresentCount / $workDays) * 100 : 0;

        // FIX: Calculate correct overtime/undertime
        $overtimeMinutes = $totalActualMinutes - $totalScheduledMinutes;

        // Get leave quota info for the user
        $currentYear = Carbon::now()->year;
        $leaveQuota = LeaveQuota::where('user_id', $user_id)
            ->where('year', $currentYear)
            ->first();

        if (!$leaveQuota) {
            $leaveQuota = new LeaveQuota([
                'user_id' => $user_id,
                'year' => $currentYear,
                'total_quota' => 0,
                'used_quota' => 0,
                'remaining_quota' => 0
            ]);
        }

        $response = [
            'status' => true,
            'message' => 'Attendance report generated successfully',
            'data' => [
                'user' => [
                    'id' => $reportUser->id,
                    'name' => $reportUser->name,
                    'email' => $reportUser->email,
                    'role' => $reportUser->role,
                    'position' => $reportUser->position,
                    'department' => $reportUser->department ? [
                        'id' => $reportUser->department->id,
                        'name' => $reportUser->department->name
                    ] : null
                ],
                'daily_records' => $enhancedReport,
                'summary' => [
                    'date_range' => [
                        'start_date' => $startDate->toDateString(),
                        'end_date' => $endDate->toDateString(),
                        'total_days' => (int)$totalDaysInRange,
                        'weekdays' => (int)$weekdays,
                        'weekends' => (int)$weekends,
                        'holidays' => (int)$holidayCount,
                        'work_days' => (int)$workDays
                    ],
                    'attendance' => [
                        'present_days' => $workingDaysPresentCount,
                        'absent_days' => $workingDaysAbsentCount,
                        'leave_days' => $workingDaysLeaveCount,
                        'leave_by_type' => $leaveCountByType,
                        'attendance_rate' => round($attendanceRate, 2) . '%',
                    ],
                    'work_hours' => [
                        'scheduled' => [
                            'total_hours' => floor($totalScheduledMinutes / 60),
                            'total_minutes' => (int)($totalScheduledMinutes % 60),
                            'hours_formatted' => sprintf("%d:%02d", floor($totalScheduledMinutes / 60), $totalScheduledMinutes % 60)
                        ],
                        'actual' => [
                            'total_hours' => floor($totalActualMinutes / 60),
                            'total_minutes' => (int)($totalActualMinutes % 60),
                            'hours_formatted' => sprintf("%d:%02d", floor($totalActualMinutes / 60), $totalActualMinutes % 60)
                        ],
                        'difference' => [
                            'total_minutes' => $overtimeMinutes,
                            'hours_formatted' => sprintf(
                                "%s%d:%02d",
                                $overtimeMinutes < 0 ? "-" : "",
                                floor(abs($overtimeMinutes) / 60),
                                abs($overtimeMinutes) % 60
                            ),
                            'type' => $overtimeMinutes > 0 ? 'overtime' : ($overtimeMinutes < 0 ? 'undertime' : 'exact')
                        ],
                        'average_hours_per_day' => $workingDaysPresentCount > 0 ?
                            round($totalActualMinutes / $workingDaysPresentCount / 60, 2) : 0
                    ],
                    'leave_quota' => [
                        'year' => $currentYear,
                        'total' => $leaveQuota->total_quota,
                        'used' => $leaveQuota->used_quota,
                        'remaining' => $leaveQuota->remaining_quota,
                        'percentage_used' => $leaveQuota->total_quota > 0
                            ? round(($leaveQuota->used_quota / $leaveQuota->total_quota) * 100, 2)
                            : 0
                    ]
                ]
            ]
        ];

        // Add report-generated timestamp
        $response['data']['generated_at'] = Carbon::now()->toDateTimeString();

        return response()->json($response);
    }

    /**
     * Get attendance records for a specific user.
     * Modified version of getAttendanceRecords that accepts a user_id parameter.
     * 
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $user_id
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function getAttendanceRecordsForUser(Request $request, $user_id)
    {
        $startDate = $request->start_date;
        $endDate = $request->end_date;

        $query = Attendance::query()
            ->where('user_id', $user_id)
            ->whereDate('created_at', '>=', $startDate)
            ->whereDate('created_at', '<=', $endDate);

        // Apply filters if provided
        if ($request->has('filter_clock_type')) {
            $query->where('clock_type', $request->filter_clock_type);
        }

        if ($request->has('filter_method')) {
            $query->where('method', $request->filter_method);
        }

        if ($request->has('filter_location')) {
            $query->where('location', 'like', '%' . $request->filter_location . '%');
        }

        // Conditionally include task logs
        if ($request->input('include_task_logs', true)) {
            $query->with('taskLogs');
        }

        return $query->orderBy('created_at', 'asc')->get();
    }

    /**
     * Helper method to process task logs and include photo URLs
     *
     * @param \Illuminate\Database\Eloquent\Collection $taskLogs
     * @return array
     */
    private function processTaskLogsWithPhotos($taskLogs)
    {
        $processedLogs = [];

        foreach ($taskLogs as $log) {
            $logData = [
                'id' => $log->id,
                'description' => $log->description,
                'created_at' => $log->created_at,
                'has_photo' => !is_null($log->photo_url),
                'photo_url' => null
            ];

            // Generate photo URL if photo exists
            if ($log->photo_url) {
                try {
                    // Check if file exists in S3 before generating URL
                    if (Storage::disk('s3')->exists($log->photo_url)) {
                        $logData['photo_url'] = Storage::disk('s3')->temporaryUrl(
                            $log->photo_url,
                            now()->addHours(24)
                        );
                        $logData['photo_expires_at'] = now()->addHours(24)->toDateTimeString();
                    } else {
                        // File doesn't exist, mark as missing
                        $logData['has_photo'] = false;
                        $logData['photo_status'] = 'missing';
                    }
                } catch (\Exception $e) {
                    // Log error but don't break the response
                    \Log::error('Failed to generate photo URL for task log in listing', [
                        'task_log_id' => $log->id,
                        'photo_path' => $log->photo_url,
                        'error' => $e->getMessage()
                    ]);
                    $logData['photo_status'] = 'error';
                }
            }

            $processedLogs[] = $logData;
        }

        return $processedLogs;
    }

    /**
     * Process the data to compute daily stats with corrected time calculations
     */
    private function processDailyStats($groupedAttendances, $includeTaskLogs = true)
    {
        $report = [];
        foreach ($groupedAttendances as $date => $records) {
            $clockIn = $records->where('clock_type', 'in')->first();
            $clockOut = $records->where('clock_type', 'out')->last();

            $totalMinutes = 0;
            $totalHours = 0;
            $hoursFormatted = "0:00";

            if ($clockIn && $clockOut) {
                $inTime = Carbon::parse($clockIn->created_at);
                $outTime = Carbon::parse($clockOut->created_at);

                if ($outTime->lt($inTime)) {
                    $totalMinutes = 0;
                } else {
                    $totalMinutes = $outTime->diffInMinutes($inTime);
                }

                $totalHours = floor($totalMinutes / 60);
                $hoursFormatted = sprintf("%d:%02d", $totalHours, $totalMinutes % 60);
            }

            // Get task logs for this day with photo URLs
            $taskLogs = [];
            if ($includeTaskLogs) {
                $allTaskLogs = collect();
                foreach ($records as $record) {
                    if ($record->relationLoaded('taskLogs')) {
                        $allTaskLogs = $allTaskLogs->merge($record->taskLogs);
                    }
                }

                // Process task logs to include photo URLs
                $taskLogs = $this->processTaskLogsWithPhotos($allTaskLogs);
            }

            $reportEntry = [
                'date' => $date,
                'day_of_week' => Carbon::parse($date)->format('l'),
                'clock_in' => $clockIn ? Carbon::parse($clockIn->created_at)->format('H:i:s') : null,
                'clock_out' => $clockOut ? Carbon::parse($clockOut->created_at)->format('H:i:s') : null,
                'clock_in_method' => $clockIn ? $clockIn->method : null,
                'clock_out_method' => $clockOut ? $clockOut->method : null,
                'location' => $clockIn ? $clockIn->location : null,
                'total_hours' => (int)$totalHours,
                'total_minutes' => (int)$totalMinutes,
                'hours_formatted' => $hoursFormatted,
            ];

            if ($includeTaskLogs) {
                $reportEntry['task_logs'] = $taskLogs;
                $reportEntry['task_logs_count'] = count($taskLogs);
                $reportEntry['photos_count'] = count(array_filter($taskLogs, function ($log) {
                    return $log['has_photo'] && !is_null($log['photo_url']);
                }));
            }

            $report[] = $reportEntry;
        }
        return $report;
    }
}
