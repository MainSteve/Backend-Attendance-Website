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
        $user_id = Auth::id();

        // Build the query
        $query = Attendance::with('taskLogs')
            ->where('user_id', $user_id);

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

        // Check if user already has a record of the same clock_type today
        if ($request->clock_type === 'in') {
            $existingClockIn = Attendance::where('user_id', Auth::id())
                ->where('clock_type', 'in')
                ->whereDate('created_at', Carbon::today())
                ->exists();

            if ($existingClockIn) {
                return response()->json([
                    'status' => false,
                    'message' => 'You have already clocked in today',
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
        $employee_id = Auth::id();
        $attendance = Attendance::with('taskLogs')
            ->where('id', $id)
            ->where('user_id', $employee_id)
            ->first();

        if (!$attendance) {
            return response()->json([
                'status' => false,
                'message' => 'Attendance record not found'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Attendance record retrieved successfully',
            'data' => $attendance
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

        // Check for clock in and clock out
        $clockIn = $todayAttendances->where('clock_type', 'in')->first();
        $clockOut = $todayAttendances->where('clock_type', 'out')->first();

        // Calculate work duration if both clock in and out exist
        $workDuration = null;
        if ($clockIn && $clockOut) {
            $inTime = Carbon::parse($clockIn->created_at);
            $outTime = Carbon::parse($clockOut->created_at);
            $workDuration = [
                'hours' => $outTime->diffInHours($inTime),
                'minutes' => $outTime->diffInMinutes($inTime) % 60,
                'total_minutes' => $outTime->diffInMinutes($inTime)
            ];
        }

        // Get task logs for today
        $taskLogs = [];
        foreach ($todayAttendances as $attendance) {
            foreach ($attendance->taskLogs as $log) {
                $taskLogs[] = [
                    'id' => $log->id,
                    'description' => $log->description,
                    'photo_url' => $log->photo_url,
                    'created_at' => $log->created_at
                ];
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Today\'s attendance records retrieved successfully',
            'data' => [
                'attendances' => $todayAttendances,
                'work_duration' => $workDuration,
                'task_logs' => $taskLogs
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
            'photo' => 'nullable|image|mimes:jpeg,png,jpg|max:5120' // 5MB max
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => VALIDATION_FAILED_MESSAGE,
                'errors' => $validator->errors()
            ], 422);
        }

        // Handle photo upload if present
        $photoUrl = null;
        if ($request->hasFile('photo') && $request->file('photo')->isValid()) {
            $path = $request->file('photo')->store('task-logs', 'public');
            $photoUrl = Storage::url($path);
        }

        // Create the task log
        $taskLog = TaskLog::create([
            'user_id' => Auth::id(),
            'attendance_id' => $attendance->id,
            'description' => $request->description,
            'photo_url' => $photoUrl
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Task log added successfully',
            'data' => $taskLog
        ], 201);
    }

    /**
     * Get attendance report for a date range.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    private function validateReportRequest(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'include_task_logs' => 'nullable|boolean',
            'filter_clock_type' => 'nullable|in:in,out',
            'filter_method' => 'nullable|in:manual,qr_code',
            'filter_location' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => VALIDATION_FAILED_MESSAGE,
                'errors' => $validator->errors()
            ], 422);
        }

        // Return a successful response or null if validation succeeds
        return null;
    }

    private function getAttendanceRecords(Request $request)
    {
        $user_id = Auth::id();
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

    private function groupAttendanceRecordsByDate($attendances)
    {
        return $attendances->groupBy(function ($item) {
            return Carbon::parse($item->created_at)->format('Y-m-d');
        });
    }

    private function processDailyStats($groupedAttendances, $includeTaskLogs = true)
    {
        // Process the data to compute daily stats
        $report = [];
        foreach ($groupedAttendances as $date => $records) {
            $clockIn = $records->where('clock_type', 'in')->first();
            $clockOut = $records->where('clock_type', 'out')->last();

            $totalHours = null;
            $totalMinutes = null;
            if ($clockIn && $clockOut) {
                $inTime = Carbon::parse($clockIn->created_at);
                $outTime = Carbon::parse($clockOut->created_at);
                $totalMinutes = $outTime->diffInMinutes($inTime);
                $totalHours = floor($totalMinutes / 60);
            }

            // Get task logs for this day if requested
            $taskLogs = [];
            if ($includeTaskLogs) {
                foreach ($records as $record) {
                    if ($record->relationLoaded('taskLogs')) {
                        foreach ($record->taskLogs as $log) {
                            $taskLogs[] = [
                                'id' => $log->id,
                                'description' => $log->description,
                                'photo_url' => $log->photo_url,
                                'created_at' => $log->created_at
                            ];
                        }
                    }
                }
            }

            $reportEntry = [
                'date' => $date,
                'day_of_week' => Carbon::parse($date)->format('l'),
                'clock_in' => $clockIn ? Carbon::parse($clockIn->created_at)->format('H:i:s') : null,
                'clock_out' => $clockOut ? Carbon::parse($clockOut->created_at)->format('H:i:s') : null,
                'clock_in_method' => $clockIn ? $clockIn->method : null,
                'clock_out_method' => $clockOut ? $clockOut->method : null,
                'location' => $clockIn ? $clockIn->location : null,
                'total_hours' => $totalHours,
                'total_minutes' => $totalMinutes,
                'hours_formatted' => $totalHours !== null ? sprintf("%d:%02d", $totalHours, $totalMinutes % 60) : null,
            ];

            if ($includeTaskLogs) {
                $reportEntry['task_logs'] = $taskLogs;
                $reportEntry['task_logs_count'] = count($taskLogs);
            }

            $report[] = $reportEntry;
        }
        return $report;
    }

    public function report(Request $request)
    {
        $validationResponse = $this->validateReportRequest($request);
        if ($validationResponse !== null) {
            return $validationResponse;
        }

        $includeTaskLogs = $request->input('include_task_logs', true);

        $attendances = $this->getAttendanceRecords($request);

        $groupedAttendances = $this->groupAttendanceRecordsByDate($attendances);

        $report = $this->processDailyStats($groupedAttendances, $includeTaskLogs);

        // Calculate date range totals
        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);
        $totalDaysInRange = $endDate->diffInDays($startDate) + 1;

        // Count weekdays/weekends in the date range
        $weekdays = 0;
        $weekends = 0;
        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
            if ($date->isWeekday()) {
                $weekdays++;
            } else {
                $weekends++;
            }
        }

        // Summary statistics
        $presentDays = count($groupedAttendances);
        $absentDays = $totalDaysInRange - $presentDays;
        $totalWorkMinutes = array_sum(array_column($report, 'total_minutes'));

        // Calculate attendance rate
        $attendanceRate = $totalDaysInRange > 0 ? ($presentDays / $totalDaysInRange) * 100 : 0;

        return response()->json([
            'status' => true,
            'message' => 'Attendance report generated successfully',
            'data' => [
                'daily_records' => $report,
                'summary' => [
                    'date_range' => [
                        'start_date' => $request->start_date,
                        'end_date' => $request->end_date,
                        'total_days' => $totalDaysInRange,
                        'weekdays' => $weekdays,
                        'weekends' => $weekends
                    ],
                    'attendance' => [
                        'present_days' => $presentDays,
                        'absent_days' => $absentDays,
                        'attendance_rate' => round($attendanceRate, 2) . '%',
                    ],
                    'work_hours' => [
                        'total_hours' => floor($totalWorkMinutes / 60),
                        'total_minutes' => $totalWorkMinutes % 60,
                        'hours_formatted' => sprintf("%d:%02d", floor($totalWorkMinutes / 60), $totalWorkMinutes % 60),
                        'average_hours_per_day' => $presentDays > 0 ? round($totalWorkMinutes / $presentDays / 60, 2) : 0
                    ]
                ]
            ]
        ]);
    }
}
