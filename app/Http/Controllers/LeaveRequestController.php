<?php

namespace App\Http\Controllers;

use App\Models\LeaveQuota;
use App\Models\LeaveRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LeaveRequestController extends Controller
{
    const LEAVE_TYPES = ['sakit', 'cuti'];
    const LEAVE_STATUSES = ['pending', 'approved', 'rejected'];
    
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
        $query = LeaveRequest::with('user');
        
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
        
        $leaveRequest = LeaveRequest::with('user')->find($id);
        
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
        
        // For 'cuti' type, check if there is sufficient quota
        $status = 'pending';
        
        // Admins can directly approve leave requests
        if ($isAdmin && $request->has('status') && in_array($request->status, self::LEAVE_STATUSES)) {
            $status = $request->status;
        }
        
        if ($request->type === 'cuti') {
            // Check quota if it's a 'cuti' type request
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
            
            // Check if there's enough quota left
            if ($leaveQuota->remaining_quota >= $duration && $status === 'approved') {
                return response()->json([
                    'status' => false,
                    'message' => 'Insufficient leave quota',
                    'data' => [
                        'requested_days' => $duration,
                        'remaining_quota' => $leaveQuota->remaining_quota
                    ]
                ], 422);
            }
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
            
            // Update quota if the request is auto-approved
            if ($status === 'approved' && $request->type === 'cuti') {
                $this->updateQuotaForApproval($leaveRequest);
            }
            
            DB::commit();
            
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
        
        $leaveRequest = LeaveRequest::with('user')->find($id);
        
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
        
        $leaveRequest = LeaveRequest::find($id);
        
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
            // If it was approved and of type 'cuti', restore quota
            if ($leaveRequest->status === 'approved' && $leaveRequest->type === 'cuti') {
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
        $leaveRequest = LeaveRequest::find($id);
        
        if (!$leaveRequest) {
            return response()->json([
                'status' => false,
                'message' => 'Leave request not found'
            ], 404);
        }
        
        DB::beginTransaction();
        
        try {
            // If it was approved and of type 'cuti', restore quota
            if ($leaveRequest->status === 'approved' && $leaveRequest->type === 'cuti') {
                $this->updateQuotaForRevocation($leaveRequest);
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
        $leaveRequests = LeaveRequest::where('user_id', $userId)
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
            'izin' => 0,
            'sakit' => 0,
            'cuti' => 0
        ];
        
        $byDuration = [
            'izin' => 0,
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
        $upcomingLeaves = LeaveRequest::where('user_id', $userId)
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
        if ($leaveRequest->type !== 'cuti') {
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
        if ($leaveRequest->type !== 'cuti') {
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
