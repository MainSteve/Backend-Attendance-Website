<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Http\Resources\AnnouncementResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class AnnouncementController extends Controller
{
    /**
     * Display a listing of announcements.
     * Regular users see only announcements for their department.
     * Admins can see all announcements.
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $query = Announcement::with(['departments', 'creator']);

        // If not admin, filter by user's department
        if (Auth::user()->role !== 'admin') {
            $query->forDepartment($user->department_id);
        }

        // Filter by active announcements only by default
        if ($request->get('show_all') !== 'true' || Auth::user()->role !== 'admin') {
            $query->active();
        }

        // Filter by departments (admin only)
        if ($request->has('department_ids') && Auth::user()->role === 'admin') {
            $departmentIds = is_array($request->get('department_ids'))
                ? $request->get('department_ids')
                : explode(',', $request->get('department_ids'));
            $query->forDepartments($departmentIds);
        }

        // Filter by importance level
        if ($request->has('importance_level')) {
            $query->byImportance($request->get('importance_level'));
        }

        // Order by importance level (high to low) and creation date (newest first)
        $announcements = $query->orderBy('importance_level', 'desc')
                              ->orderBy('created_at', 'desc')
                              ->paginate($request->get('per_page', 15));

        return AnnouncementResource::collection($announcements)->response();
    }

    /**
     * Store a newly created announcement in storage.
     * Only admins can create announcements.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'importance_level' => 'required|integer|in:1,2,3',
            'department_ids' => 'required|array|min:1',
            'department_ids.*' => 'exists:departments,id',
            'is_active' => 'sometimes|boolean',
        ]);

        $validated['created_by'] = Auth::id();
        $departmentIds = $validated['department_ids'];
        unset($validated['department_ids']);
        
        $announcement = Announcement::create($validated);
        $announcement->departments()->attach($departmentIds);
        $announcement->load(['departments', 'creator']);

        return (new AnnouncementResource($announcement))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified announcement.
     */
    public function show(Announcement $announcement): JsonResponse
    {
        $user = Auth::user();

        // Check if user can view this announcement
        if (Auth::user()->role !== 'admin' && !$announcement->departments->pluck('id')->contains($user->department_id)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $announcement->load(['departments', 'creator']);
        
        return (new AnnouncementResource($announcement))->response();
    }

    /**
     * Update the specified announcement in storage.
     * Only admins can update announcements.
     */
    public function update(Request $request, Announcement $announcement): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'content' => 'sometimes|string',
            'importance_level' => 'sometimes|integer|in:1,2,3',
            'department_ids' => 'sometimes|array|min:1',
            'department_ids.*' => 'exists:departments,id',
            'is_active' => 'sometimes|boolean',
        ]);

        $departmentIds = null;
        if (isset($validated['department_ids'])) {
            $departmentIds = $validated['department_ids'];
            unset($validated['department_ids']);
        }

        $announcement->update($validated);
        
        if ($departmentIds !== null) {
            $announcement->departments()->sync($departmentIds);
        }
        
        $announcement->load(['departments', 'creator']);

        return (new AnnouncementResource($announcement))->response();
    }

    /**
     * Remove the specified announcement from storage.
     * Only admins can delete announcements.
     */
    public function destroy(Announcement $announcement): JsonResponse
    {
        $announcement->delete();
        
        return response()->json(null, 204);
    }

    /**
     * Get announcements for the authenticated user's department (active only).
     * This is a convenience endpoint for regular users.
     */
    public function myDepartmentAnnouncements(): JsonResponse
    {
        $user = Auth::user();
        
        if (!$user->department_id) {
            return response()->json(['message' => 'User is not assigned to any department'], 400);
        }

        $announcements = Announcement::with(['departments', 'creator'])
            ->forDepartment($user->department_id)
            ->active()
            ->orderBy('importance_level', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return AnnouncementResource::collection($announcements)->response();
    }

    /**
     * Toggle the active status of an announcement.
     * Only admins can use this endpoint.
     */
    public function toggleActive(Announcement $announcement): JsonResponse
    {
        $announcement->update(['is_active' => !$announcement->is_active]);
        $announcement->load(['departments', 'creator']);

        return (new AnnouncementResource($announcement))->response();
    }

    /**
     * Get announcement statistics.
     * Only admins can access this endpoint.
     */
    public function statistics(): JsonResponse
    {
        $stats = [
            'total_announcements' => Announcement::count(),
            'active_announcements' => Announcement::active()->count(),
            'expired_announcements' => Announcement::where('expires_at', '<=', now())->count(),
            'by_importance' => [
                'low' => Announcement::byImportance(1)->count(),
                'medium' => Announcement::byImportance(2)->count(),
                'high' => Announcement::byImportance(3)->count(),
            ],
            'by_department' => Announcement::with('departments')
                ->get()
                ->flatMap(function ($announcement) {
                    return $announcement->departments->map(function ($department) {
                        return ['department_name' => $department->name, 'department_id' => $department->id];
                    });
                })
                ->groupBy('department_name')
                ->map(function ($group, $departmentName) {
                    return [
                        'department' => $departmentName,
                        'total' => $group->count()
                    ];
                })
                ->values(),
        ];

        return response()->json($stats);
    }
}
