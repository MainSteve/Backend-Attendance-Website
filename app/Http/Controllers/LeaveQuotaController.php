<?php

namespace App\Http\Controllers;

use App\Models\LeaveQuota;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class LeaveQuotaController extends Controller
{
    /**
     * Display leave quotas for the authenticated user or a specific user for admins.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $currentUser = Auth::user();
        $isAdmin = $currentUser->role === 'admin';

        // Build the query
        $query = LeaveQuota::with('user');

        // For non-admin users, only show their own quotas
        if (!$isAdmin) {
            $query->where('user_id', $currentUser->id);
        } elseif ($request->has('user_id')) {
            // Admin can filter by user_id
            $query->where('user_id', $request->user_id);
        }

        // Filter by year
        if ($request->has('year')) {
            $query->where('year', $request->year);
        } else {
            // Default to current year
            $query->where('year', now()->year);
        }

        $leaveQuotas = $query->get();

        return response()->json([
            'status' => true,
            'message' => 'Leave quotas retrieved successfully',
            'data' => $leaveQuotas
        ]);
    }

    /**
     * Display a specific leave quota.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $currentUser = Auth::user();
        $isAdmin = $currentUser->role === 'admin';

        $leaveQuota = LeaveQuota::with('user')->find($id);

        if (!$leaveQuota) {
            return response()->json([
                'status' => false,
                'message' => 'Leave quota not found'
            ], 404);
        }

        // Check if the user has permission to view this quota
        if (!$isAdmin && $leaveQuota->user_id !== $currentUser->id) {
            return response()->json([
                'status' => false,
                'message' => 'You do not have permission to view this leave quota'
            ], 403);
        }

        return response()->json([
            'status' => true,
            'message' => 'Leave quota retrieved successfully',
            'data' => $leaveQuota
        ]);
    }

    /**
     * Store a new leave quota.
     * Admin only endpoint.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'year' => 'required|integer|min:2020|max:2050',
            'total_quota' => 'required|integer|min:0|max:365',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Check if a quota already exists for this user and year
        $existingQuota = LeaveQuota::where('user_id', $request->user_id)
            ->where('year', $request->year)
            ->first();

        if ($existingQuota) {
            return response()->json([
                'status' => false,
                'message' => 'A leave quota already exists for this user and year',
                'data' => $existingQuota
            ], 422);
        }

        // Create the new leave quota
        $leaveQuota = LeaveQuota::create([
            'user_id' => $request->user_id,
            'year' => $request->year,
            'total_quota' => $request->total_quota,
            'used_quota' => 0,
            'remaining_quota' => $request->total_quota
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Leave quota created successfully',
            'data' => $leaveQuota
        ], 201);
    }

    /**
     * Update a leave quota.
     * Admin only endpoint.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $leaveQuota = LeaveQuota::find($id);

        if (!$leaveQuota) {
            return response()->json([
                'status' => false,
                'message' => 'Leave quota not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'total_quota' => 'required|integer|min:' . $leaveQuota->used_quota . '|max:365',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Update the quota
        $leaveQuota->total_quota = $request->total_quota;
        $leaveQuota->updateRemainingQuota();

        return response()->json([
            'status' => true,
            'message' => 'Leave quota updated successfully',
            'data' => $leaveQuota
        ]);
    }

    /**
     * Generate leave quotas for all users for a specific year.
     * Admin only endpoint.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function generateYearlyQuotas(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'year' => 'required|integer|min:2020|max:2050',
            'default_quota' => 'required|integer|min:0|max:365',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $year = $request->year;
        $defaultQuota = $request->default_quota;

        // Get all users except admins
        $users = User::where('role', '!=', 'admin')->get();
        $created = 0;
        $skipped = 0;

        foreach ($users as $user) {
            // Check if quota already exists for this user and year
            $existingQuota = LeaveQuota::where('user_id', $user->id)
                ->where('year', $year)
                ->first();

            if (!$existingQuota) {
                // Create new quota
                LeaveQuota::create([
                    'user_id' => $user->id,
                    'year' => $year,
                    'total_quota' => $defaultQuota,
                    'used_quota' => 0,
                    'remaining_quota' => $defaultQuota
                ]);

                $created++;
            } else {
                $skipped++;
            }
        }

        return response()->json([
            'status' => true,
            'message' => "Generated leave quotas for $created users, skipped $skipped existing quotas",
            'data' => [
                'year' => $year,
                'default_quota' => $defaultQuota,
                'created' => $created,
                'skipped' => $skipped
            ]
        ]);
    }
}
