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
                'hours' => floor($outTime->diffInMinutes($inTime)),
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
     * Process the data to compute daily stats with corrected time calculations
     */
    private function processDailyStats($groupedAttendances, $includeTaskLogs = true)
    {
        // Process the data to compute daily stats
        $report = [];
        foreach ($groupedAttendances as $date => $records) {
            $clockIn = $records->where('clock_type', 'in')->first();
            $clockOut = $records->where('clock_type', 'out')->last();

            $totalMinutes = 0; // Default to 0 instead of null
            $totalHours = 0;   // Default to 0 instead of null
            $hoursFormatted = "0:00"; // Default formatted value

            if ($clockIn && $clockOut) {
                $inTime = Carbon::parse($clockIn->created_at);
                $outTime = Carbon::parse($clockOut->created_at);

                // FIX: Ensure proper time difference calculation
                if ($outTime->lt($inTime)) {
                    // If clock-out time is before clock-in time (error or midnight crossing)
                    // Just set to 0 or handle as needed for your business logic
                    $totalMinutes = 0;
                } else {
                    $totalMinutes = $outTime->diffInMinutes($inTime);
                }

                $totalHours = floor($totalMinutes / 60);
                $hoursFormatted = sprintf("%d:%02d", $totalHours, $totalMinutes % 60);
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
                'total_hours' => (int)$totalHours, // Cast to integer
                'total_minutes' => (int)$totalMinutes, // Cast to integer
                'hours_formatted' => $hoursFormatted,
            ];

            if ($includeTaskLogs) {
                $reportEntry['task_logs'] = $taskLogs;
                $reportEntry['task_logs_count'] = count($taskLogs);
            }

            $report[] = $reportEntry;
        }
        return $report;
    }
}
