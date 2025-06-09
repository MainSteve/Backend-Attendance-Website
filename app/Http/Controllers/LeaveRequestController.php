<?php

namespace App\Http\Controllers;

use App\Models\LeaveQuota;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestProof;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LeaveRequestController extends Controller
{
    const LEAVE_TYPES = ['sakit', 'cuti'];
    const LEAVE_STATUSES = ['pending', 'approved', 'rejected'];

    // File upload configuration
    const MAX_FILE_SIZE = 5120; // 5MB in KB
    const ALLOWED_FILE_TYPES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/webp',
    ];

    /**
     * Display leave requests for the authenticated user or all users for admins.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $currentUser = Auth::user();
        $isAdmin = $currentUser->role === 'admin';

        // Build the query
        $query = LeaveRequest::with(['user', 'proofs']);

        // For non-admin users, only show their own requests
        if (!$isAdmin) {
            $query->where('user_id', $currentUser->id);
        } elseif ($request->has('user_id')) {
            // Admin can filter by user_id
            $query->where('user_id', $request->user_id);
        }

        // Filter by status
        if ($request->has('status') && in_array($request->status, self::LEAVE_STATUSES)) {
            $query->where('status', $request->status);
        }

        // Filter by type
        if ($request->has('type') && in_array($request->type, self::LEAVE_TYPES)) {
            $query->where('type', $request->type);
        }

        // Filter by date range
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->where(function ($q) use ($request) {
                // Requests that start within the range
                $q->whereBetween('start_date', [$request->start_date, $request->end_date])
                    // Or requests that end within the range
                    ->orWhereBetween('end_date', [$request->start_date, $request->end_date])
                    // Or requests that span the entire range
                    ->orWhere(function ($inner) use ($request) {
                        $inner->where('start_date', '<=', $request->start_date)
                            ->where('end_date', '>=', $request->end_date);
                    });
            });
        } elseif ($request->has('year')) {
            // Filter by year
            $year = $request->year;
            $query->where(function ($q) use ($year) {
                $q->whereYear('start_date', $year)
                    ->orWhereYear('end_date', $year);
            });
        } else {
            // Default to current year
            $currentYear = now()->year;
            $query->where(function ($q) use ($currentYear) {
                $q->whereYear('start_date', $currentYear)
                    ->orWhereYear('end_date', $currentYear);
            });
        }

        // Handle sort (default is newest first)
        $sortField = $request->query('sort_by', 'created_at');
        $sortDirection = $request->query('sort_direction', 'desc');

        // Validate sort field is allowed
        $allowedSortFields = ['created_at', 'start_date', 'end_date', 'status', 'type'];
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

        $leaveRequests = $query->paginate($perPage);

        // Calculate duration for each leave request
        $leaveRequests->getCollection()->transform(function ($request) {
            $request->duration = $request->getDurationAttribute();
            return $request;
        });

        // Add query parameters to pagination links
        $leaveRequests->appends($request->query());

        return response()->json([
            'status' => true,
            'message' => 'Leave requests retrieved successfully',
            'data' => $leaveRequests
        ]);
    }

    /**
     * Display a specific leave request.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $currentUser = Auth::user();
        $isAdmin = $currentUser->role === 'admin';

        $leaveRequest = LeaveRequest::with(['user', 'proofs.verifier'])->find($id);

        if (!$leaveRequest) {
            return response()->json([
                'status' => false,
                'message' => 'Leave request not found'
            ], 404);
        }

        // Check if the user has permission to view this request
        if (!$isAdmin && $leaveRequest->user_id !== $currentUser->id) {
            return response()->json([
                'status' => false,
                'message' => 'You do not have permission to view this leave request'
            ], 403);
        }

        // Calculate duration
        $leaveRequest->duration = $leaveRequest->getDurationAttribute();

        return response()->json([
            'status' => true,
            'message' => 'Leave request retrieved successfully',
            'data' => $leaveRequest
        ]);
    }

    /**
     * Store a new leave request.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $currentUser = Auth::user();
        $isAdmin = $currentUser->role === 'admin';

        $validationRules = [
            'type' => 'required|in:' . implode(',', self::LEAVE_TYPES),
            'reason' => 'nullable|string|max:1000',
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'required|date|after_or_equal:start_date',
            'proofs' => 'nullable|array|max:5', // Allow up to 5 files
            'proofs.*' => [
                'file',
                'max:' . self::MAX_FILE_SIZE,
                function ($attribute, $value, $fail) {
                    if (!in_array($value->getMimeType(), self::ALLOWED_FILE_TYPES)) {
                        $fail('The ' . $attribute . ' must be a valid image (JPEG, PNG, WebP) or PDF file.');
                    }
                },
            ],
            'proof_descriptions' => 'nullable|array',
            'proof_descriptions.*' => 'nullable|string|max:255',
        ];

        // Admin can create leave requests for any user
        if ($isAdmin && $request->has('user_id')) {
            $validationRules['user_id'] = 'required|exists:users,id';
            $userId = $request->user_id;
        } else {
            $userId = $currentUser->id;
        }

        $validator = Validator::make($request->all(), $validationRules);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Calculate duration of the leave
        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);
        $duration = $endDate->diffInDays($startDate) + 1;

        // Check for overlapping leave requests
        $overlappingRequests = LeaveRequest::where('user_id', $userId)
            ->where('status', '!=', 'rejected')
            ->where(function ($query) use ($startDate, $endDate) {
                $query->whereBetween('start_date', [$startDate, $endDate])
                    ->orWhereBetween('end_date', [$startDate, $endDate])
                    ->orWhere(function ($q) use ($startDate, $endDate) {
                        $q->where('start_date', '<=', $startDate)
                            ->where('end_date', '>=', $endDate);
                    });
            })->get();

        if ($overlappingRequests->count() > 0) {
            return response()->json([
                'status' => false,
                'message' => 'You have overlapping leave requests for the selected date range',
                'data' => $overlappingRequests
            ], 422);
        }

        // Determine initial status based on leave type
        $status = 'pending';

        // For 'sakit' type, auto-approve the request
        if ($request->type === 'sakit') {
            $status = 'approved';
        }

        // Admins can directly set status for any type
        if ($isAdmin && $request->has('status') && in_array($request->status, self::LEAVE_STATUSES)) {
            $status = $request->status;
        }

        // Check quota for both types before creating the request
        $currentYear = Carbon::now()->year;

        // Get leave quota for the current year
        $leaveQuota = LeaveQuota::where('user_id', $userId)
            ->where('year', $currentYear)
            ->first();

        if (!$leaveQuota) {
            // Create a new quota with default values if not exists
            $leaveQuota = LeaveQuota::create([
                'user_id' => $userId,
                'year' => $currentYear,
                'total_quota' => 0, // Default value
                'used_quota' => 0,
                'remaining_quota' => 0
            ]);
        }

        // Check if there's enough quota left for both 'cuti' and 'sakit'
        if ($leaveQuota->remaining_quota < $duration) {
            return response()->json([
                'status' => false,
                'message' => 'Insufficient leave quota',
                'data' => [
                    'requested_days' => $duration,
                    'remaining_quota' => $leaveQuota->remaining_quota
                ]
            ], 422);
        }

        // Create the leave request
        DB::beginTransaction();

        try {
            $leaveRequest = LeaveRequest::create([
                'user_id' => $userId,
                'type' => $request->type,
                'reason' => $request->reason,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'status' => $status
            ]);

            // Handle file uploads if provided
            if ($request->hasFile('proofs')) {
                $uploadedProofs = $this->uploadProofs($request, $leaveRequest);

                if (!$uploadedProofs['success']) {
                    DB::rollBack();
                    return response()->json([
                        'status' => false,
                        'message' => 'Failed to upload proof files',
                        'error' => $uploadedProofs['message']
                    ], 500);
                }
            }

            // Update quota based on type and status
            if ($request->type === 'sakit') {
                // For 'sakit', always update quota immediately (since it's auto-approved)
                $this->updateQuotaForApproval($leaveRequest);
            } elseif ($request->type === 'cuti' && $status === 'approved') {
                // For 'cuti', only update quota if it's approved
                $this->updateQuotaForApproval($leaveRequest);
            }

            DB::commit();

            // Load relationships for response
            $leaveRequest->load(['proofs', 'user']);

            // Add duration to response
            $leaveRequest->duration = $duration;

            return response()->json([
                'status' => true,
                'message' => 'Leave request created successfully',
                'data' => $leaveRequest
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating leave request: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to create leave request',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload proof files to S3
     *
     * @param Request $request
     * @param LeaveRequest $leaveRequest
     * @return array
     */
    private function uploadProofs(Request $request, LeaveRequest $leaveRequest): array
    {
        try {
            $uploadedFiles = [];
            $proofs = $request->file('proofs');
            $descriptions = $request->input('proof_descriptions', []);

            foreach ($proofs as $index => $file) {
                $originalName = $file->getClientOriginalName();
                $extension = $file->getClientOriginalExtension();
                $mimeType = $file->getMimeType();
                $size = $file->getSize();

                // Generate unique filename
                $filename = 'leave-request-' . $leaveRequest->id . '-' .
                    Str::random(8) . '-' . time() . '.' . $extension;

                // Define S3 path
                $path = 'leave-requests/' . $leaveRequest->user_id . '/' . $leaveRequest->id . '/' . $filename;

                // Upload to S3
                $uploaded = Storage::disk('s3')->put($path, file_get_contents($file), 'private');

                if (!$uploaded) {
                    throw new \Exception('Failed to upload file: ' . $originalName);
                }

                // Create proof record
                $proof = LeaveRequestProof::create([
                    'leave_request_id' => $leaveRequest->id,
                    'filename' => $originalName,
                    'path' => $path,
                    'disk' => 's3',
                    'mime_type' => $mimeType,
                    'size' => $size,
                    'description' => $descriptions[$index] ?? null,
                ]);

                $uploadedFiles[] = $proof;
            }

            return [
                'success' => true,
                'files' => $uploadedFiles
            ];
        } catch (\Exception $e) {
            Log::error('Error uploading proof files: ' . $e->getMessage());

            // Clean up any uploaded files on error
            foreach ($uploadedFiles as $proof) {
                Storage::disk('s3')->delete($proof->path);
                $proof->delete();
            }

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Add proof files to an existing leave request
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function addProofs(Request $request, $id)
    {
        $currentUser = Auth::user();
        $isAdmin = $currentUser->role === 'admin';

        $leaveRequest = LeaveRequest::find($id);

        if (!$leaveRequest) {
            return response()->json([
                'status' => false,
                'message' => 'Leave request not found'
            ], 404);
        }

        // Check permission
        if (!$isAdmin && $leaveRequest->user_id !== $currentUser->id) {
            return response()->json([
                'status' => false,
                'message' => 'You do not have permission to add proofs to this leave request'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'proofs' => 'required|array|max:5',
            'proofs.*' => [
                'file',
                'max:' . self::MAX_FILE_SIZE,
                function ($attribute, $value, $fail) {
                    if (!in_array($value->getMimeType(), self::ALLOWED_FILE_TYPES)) {
                        $fail('The ' . $attribute . ' must be a valid image (JPEG, PNG, GIF, WebP) or PDF file.');
                    }
                },
            ],
            'proof_descriptions' => 'nullable|array',
            'proof_descriptions.*' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Check if total proofs would exceed limit
        $currentProofsCount = $leaveRequest->proofs()->count();
        $newProofsCount = count($request->file('proofs'));

        if ($currentProofsCount + $newProofsCount > 5) {
            return response()->json([
                'status' => false,
                'message' => 'Maximum 5 proof files allowed per leave request'
            ], 422);
        }

        DB::beginTransaction();

        try {
            $uploadResult = $this->uploadProofs($request, $leaveRequest);

            if (!$uploadResult['success']) {
                DB::rollBack();
                return response()->json([
                    'status' => false,
                    'message' => 'Failed to upload proof files',
                    'error' => $uploadResult['message']
                ], 500);
            }

            DB::commit();

            // Load updated relationships
            $leaveRequest->load(['proofs', 'user']);

            return response()->json([
                'status' => true,
                'message' => 'Proof files uploaded successfully',
                'data' => [
                    'leave_request' => $leaveRequest,
                    'uploaded_proofs' => $uploadResult['files']
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error adding proof files: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to add proof files',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a specific proof file
     *
     * @param int $proofId
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteProof($proofId)
    {
        $currentUser = Auth::user();
        $isAdmin = $currentUser->role === 'admin';

        $proof = LeaveRequestProof::with('leaveRequest')->find($proofId);

        if (!$proof) {
            return response()->json([
                'status' => false,
                'message' => 'Proof file not found'
            ], 404);
        }

        // Check permission
        if (!$isAdmin && $proof->leaveRequest->user_id !== $currentUser->id) {
            return response()->json([
                'status' => false,
                'message' => 'You do not have permission to delete this proof file'
            ], 403);
        }

        try {
            // Delete file from S3
            Storage::disk($proof->disk)->delete($proof->path);

            // Delete database record
            $proof->delete();

            return response()->json([
                'status' => true,
                'message' => 'Proof file deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting proof file: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to delete proof file',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a temporary URL for a proof file
     *
     * @param int $proofId
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getProofUrl($proofId, Request $request)
    {
        $currentUser = Auth::user();
        $isAdmin = $currentUser->role === 'admin';

        $proof = LeaveRequestProof::with('leaveRequest')->find($proofId);

        if (!$proof) {
            return response()->json([
                'status' => false,
                'message' => 'Proof file not found'
            ], 404);
        }

        // Check permission
        if (!$isAdmin && $proof->leaveRequest->user_id !== $currentUser->id) {
            return response()->json([
                'status' => false,
                'message' => 'You do not have permission to access this proof file'
            ], 403);
        }

        try {
            $minutes = $request->input('expires_in', 60); // Default 1 hour
            $url = $proof->getTemporaryUrl($minutes);

            return response()->json([
                'status' => true,
                'message' => 'Temporary URL generated successfully',
                'data' => [
                    'url' => $url,
                    'expires_in_minutes' => $minutes,
                    'expires_at' => now()->addMinutes($minutes)->toISOString()
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error generating temporary URL: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to generate temporary URL',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark a proof as verified (Admin only)
     *
     * @param int $proofId
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyProof($proofId)
    {
        $currentUser = Auth::user();

        $proof = LeaveRequestProof::with('leaveRequest')->find($proofId);

        if (!$proof) {
            return response()->json([
                'status' => false,
                'message' => 'Proof file not found'
            ], 404);
        }

        if ($proof->is_verified) {
            return response()->json([
                'status' => false,
                'message' => 'Proof file is already verified'
            ], 422);
        }

        try {
            $proof->markAsVerified($currentUser);

            return response()->json([
                'status' => true,
                'message' => 'Proof file verified successfully',
                'data' => $proof->load('verifier')
            ]);
        } catch (\Exception $e) {
            Log::error('Error verifying proof: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to verify proof file',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a leave request's status.
     * Admin only endpoint.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:' . implode(',', self::LEAVE_STATUSES),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $leaveRequest = LeaveRequest::with(['user', 'proofs'])->find($id);

        if (!$leaveRequest) {
            return response()->json([
                'status' => false,
                'message' => 'Leave request not found'
            ], 404);
        }

        $oldStatus = $leaveRequest->status;
        $newStatus = $request->status;

        // If status hasn't changed, return early
        if ($oldStatus === $newStatus) {
            return response()->json([
                'status' => true,
                'message' => 'Leave request status unchanged',
                'data' => $leaveRequest
            ]);
        }

        // Prevent changing status of 'sakit' requests from 'approved'
        if ($leaveRequest->type === 'sakit' && $oldStatus === 'approved' && $newStatus !== 'approved') {
            return response()->json([
                'status' => false,
                'message' => 'Cannot change status of approved sick leave requests'
            ], 422);
        }

        DB::beginTransaction();

        try {
            // Update quota based on status change
            if ($leaveRequest->type === 'cuti') {
                if ($oldStatus !== 'approved' && $newStatus === 'approved') {
                    // Newly approved - deduct from quota
                    $this->updateQuotaForApproval($leaveRequest);
                } else if ($oldStatus === 'approved' && $newStatus !== 'approved') {
                    // Approval revoked - add back to quota
                    $this->updateQuotaForRevocation($leaveRequest);
                }
            }

            // Update the status
            $leaveRequest->status = $newStatus;
            $leaveRequest->save();

            DB::commit();

            // Calculate duration
            $leaveRequest->duration = $leaveRequest->getDurationAttribute();

            return response()->json([
                'status' => true,
                'message' => 'Leave request status updated successfully',
                'data' => $leaveRequest
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating leave request status: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to update leave request status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel a leave request.
     * Can be used by the owner of the request or an admin.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function cancel($id)
    {
        $currentUser = Auth::user();
        $isAdmin = $currentUser->role === 'admin';

        $leaveRequest = LeaveRequest::with('proofs')->find($id);

        if (!$leaveRequest) {
            return response()->json([
                'status' => false,
                'message' => 'Leave request not found'
            ], 404);
        }

        // Check if user has permission to cancel this request
        if (!$isAdmin && $leaveRequest->user_id !== $currentUser->id) {
            return response()->json([
                'status' => false,
                'message' => 'You do not have permission to cancel this leave request'
            ], 403);
        }

        // Check if request is in a cancellable state
        if ($leaveRequest->status === 'rejected') {
            return response()->json([
                'status' => false,
                'message' => 'This leave request has already been rejected'
            ], 422);
        }

        if ($leaveRequest->start_date <= Carbon::today() && $leaveRequest->status === 'approved') {
            return response()->json([
                'status' => false,
                'message' => 'Cannot cancel an approved leave request that has already started'
            ], 422);
        }

        DB::beginTransaction();

        try {
            // Restore quota for both types if they were approved
            if ($leaveRequest->status === 'approved') {
                $this->updateQuotaForRevocation($leaveRequest);
            }

            // Update status to rejected (which is our way of marking it as cancelled)
            $leaveRequest->status = 'rejected';
            $leaveRequest->save();

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Leave request cancelled successfully',
                'data' => $leaveRequest
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error cancelling leave request: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to cancel leave request',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a leave request.
     * Admin only endpoint.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $leaveRequest = LeaveRequest::with('proofs')->find($id);

        if (!$leaveRequest) {
            return response()->json([
                'status' => false,
                'message' => 'Leave request not found'
            ], 404);
        }

        DB::beginTransaction();

        try {
            // Restore quota for both types if they were approved
            if ($leaveRequest->status === 'approved') {
                $this->updateQuotaForRevocation($leaveRequest);
            }

            // Delete all proof files first (the model's boot method will handle S3 cleanup)
            foreach ($leaveRequest->proofs as $proof) {
                $proof->delete();
            }

            // Delete the request
            $leaveRequest->delete();

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Leave request deleted successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting leave request: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to delete leave request',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get leave quota summary for the authenticated user or a specific user for admins.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getQuotaSummary(Request $request)
    {
        $currentUser = Auth::user();
        $isAdmin = $currentUser->role === 'admin';

        // Determine which user to get summary for
        $userId = $currentUser->id;
        $targetUser = $currentUser;

        if ($isAdmin && $request->has('user_id')) {
            $userId = $request->user_id;
            $targetUser = User::find($userId);

            if (!$targetUser) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found'
                ], 404);
            }
        }

        // Determine which year to get summary for
        $year = $request->has('year') ? $request->year : Carbon::now()->year;

        // Get leave quota for the year
        $leaveQuota = LeaveQuota::where('user_id', $userId)
            ->where('year', $year)
            ->first();

        if (!$leaveQuota) {
            // Create a default quota if none exists
            $leaveQuota = LeaveQuota::create([
                'user_id' => $userId,
                'year' => $year,
                'total_quota' => 12, // Default value
                'used_quota' => 0,
                'remaining_quota' => 12
            ]);
        }

        // Get leave requests for the year
        $leaveRequests = LeaveRequest::with('proofs')
            ->where('user_id', $userId)
            ->where(function ($query) use ($year) {
                $query->whereYear('start_date', $year)
                    ->orWhereYear('end_date', $year);
            })
            ->get();

        // Count by status and type
        $byStatus = [
            'pending' => 0,
            'approved' => 0,
            'rejected' => 0
        ];

        $byType = [
            'sakit' => 0,
            'cuti' => 0
        ];

        $byDuration = [
            'sakit' => 0,
            'cuti' => 0,
            'total' => 0
        ];

        foreach ($leaveRequests as $leave) {
            $byStatus[$leave->status]++;

            if ($leave->status === 'approved') {
                $byType[$leave->type]++;

                // Calculate duration
                $duration = $leave->getDurationAttribute();
                $byDuration[$leave->type] += $duration;
                $byDuration['total'] += $duration;
            }
        }

        // Get upcoming leave requests
        $upcomingLeaves = LeaveRequest::with('proofs')
            ->where('user_id', $userId)
            ->where('status', 'approved')
            ->where('start_date', '>=', Carbon::today())
            ->orderBy('start_date', 'asc')
            ->take(5)
            ->get();

        foreach ($upcomingLeaves as $leave) {
            $leave->duration = $leave->getDurationAttribute();
        }

        return response()->json([
            'status' => true,
            'message' => 'Leave quota summary retrieved successfully',
            'data' => [
                'user' => [
                    'id' => $targetUser->id,
                    'name' => $targetUser->name,
                    'email' => $targetUser->email,
                    'role' => $targetUser->role,
                    'position' => $targetUser->position
                ],
                'year' => $year,
                'quota' => [
                    'total' => $leaveQuota->total_quota,
                    'used' => $leaveQuota->used_quota,
                    'remaining' => $leaveQuota->remaining_quota,
                    'percentage_used' => $leaveQuota->total_quota > 0
                        ? round(($leaveQuota->used_quota / $leaveQuota->total_quota) * 100, 2)
                        : 0
                ],
                'leave_counts' => [
                    'by_status' => $byStatus,
                    'by_type' => $byType
                ],
                'leave_days' => $byDuration,
                'upcoming_leaves' => $upcomingLeaves
            ]
        ]);
    }

    /**
     * Update leave quota when a leave request is approved.
     *
     * @param LeaveRequest $leaveRequest
     * @return void
     */
    private function updateQuotaForApproval(LeaveRequest $leaveRequest)
    {
        // Now handles both 'cuti' and 'sakit' types
        if (!in_array($leaveRequest->type, ['cuti', 'sakit'])) {
            return;
        }

        $duration = $leaveRequest->getDurationAttribute();
        $year = Carbon::parse($leaveRequest->start_date)->year;

        $leaveQuota = LeaveQuota::where('user_id', $leaveRequest->user_id)
            ->where('year', $year)
            ->first();

        if (!$leaveQuota) {
            // Create a new quota with default values if not exists
            $leaveQuota = LeaveQuota::create([
                'user_id' => $leaveRequest->user_id,
                'year' => $year,
                'total_quota' => 12, // Default value
                'used_quota' => 0,
                'remaining_quota' => 12
            ]);
        }

        // Increment used quota and recalculate remaining quota
        $leaveQuota->used_quota += $duration;
        $leaveQuota->updateRemainingQuota();
    }

    /**
     * Update leave quota when a leave request approval is revoked.
     *
     * @param LeaveRequest $leaveRequest
     * @return void
     */
    private function updateQuotaForRevocation(LeaveRequest $leaveRequest)
    {
        // Now handles both 'cuti' and 'sakit' types
        if (!in_array($leaveRequest->type, ['cuti', 'sakit'])) {
            return;
        }

        $duration = $leaveRequest->getDurationAttribute();
        $year = Carbon::parse($leaveRequest->start_date)->year;

        $leaveQuota = LeaveQuota::where('user_id', $leaveRequest->user_id)
            ->where('year', $year)
            ->first();

        if ($leaveQuota) {
            // Decrement used quota and recalculate remaining quota
            $leaveQuota->used_quota = max(0, $leaveQuota->used_quota - $duration);
            $leaveQuota->updateRemainingQuota();
        }
    }
}
