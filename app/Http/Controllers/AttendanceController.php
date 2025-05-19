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

        // Default to 30 days if not specified
        $days = $request->query('days', 30);

        $attendances = Attendance::with('taskLogs')
            ->where('user_id', $user_id)
            ->orderBy('created_at', 'desc')
            ->whereDate('created_at', '>=', Carbon::now()->subDays($days))
            ->paginate(15);

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
        $attendance = Attendance::where('id', $id)
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
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => VALIDATION_FAILED_MESSAGE,
                'errors' => $validator->errors()
            ], 422);
        }

        // Return a successful response or null if validation succeeds
        return response()->json(['status' => true, 'message' => 'Validation successful'], 200);
    }

    private function getAttendanceRecords(Request $request)
    {
        $user_id = Auth::id();
        $startDate = $request->start_date;
        $endDate = $request->end_date;

        return Attendance::with('taskLogs')
            ->where('user_id', $user_id)
            ->whereDate('created_at', '>=', $startDate)
            ->whereDate('created_at', '<=', $endDate)
            ->orderBy('created_at', 'asc')
            ->get();
    }

    private function groupAttendanceRecordsByDate($attendances)
    {
        return $attendances->groupBy(function ($item) {
            return Carbon::parse($item->created_at)->format('Y-m-d');
        });
    }

    private function processDailyStats($groupedAttendances)
    {
        // Process the data to compute daily stats
        $report = [];
        foreach ($groupedAttendances as $date => $records) {
            $clockIn = $records->where('clock_type', 'in')->first();
            $clockOut = $records->where('clock_type', 'out')->first();

            $totalHours = null;
            if ($clockIn && $clockOut) {
                $inTime = Carbon::parse($clockIn->created_at);
                $outTime = Carbon::parse($clockOut->created_at);
                $totalHours = $outTime->diffInHours($inTime);
            }

            // Get task logs for this day
            $taskLogs = [];
            foreach ($records as $record) {
                foreach ($record->taskLogs as $log) {
                    $taskLogs[] = [
                        'id' => $log->id,
                        'description' => $log->description,
                        'photo_url' => $log->photo_url,
                        'created_at' => $log->created_at
                    ];
                }
            }

            $report[] = [
                'date' => $date,
                'clock_in' => $clockIn ? Carbon::parse($clockIn->created_at)->format('H:i:s') : null,
                'clock_out' => $clockOut ? Carbon::parse($clockOut->created_at)->format('H:i:s') : null,
                'clock_in_method' => $clockIn ? $clockIn->method : null,
                'clock_out_method' => $clockOut ? $clockOut->method : null,
                'location' => $clockIn ? $clockIn->location : null,
                'total_hours' => $totalHours,
                'task_logs' => $taskLogs
            ];
        }
        return $report;
    }

    public function report(Request $request)
    {
        $this->validateReportRequest($request);

        $attendances = $this->getAttendanceRecords($request);

        $groupedAttendances = $this->groupAttendanceRecordsByDate($attendances);

        $report = $this->processDailyStats($groupedAttendances);

        // Summary statistics
        $totalDays = count($report);
        $presentDays = count($groupedAttendances);
        $absentDays = $totalDays - $presentDays;
        $totalWorkHours = array_sum(array_column($report, 'total_hours'));

        return response()->json([
            'status' => true,
            'message' => 'Attendance report generated successfully',
            'data' => [
                'daily_records' => $report,
                'summary' => [
                    'total_days' => $totalDays,
                    'present_days' => $presentDays,
                    'absent_days' => $absentDays,
                    'total_work_hours' => $totalWorkHours
                ]
            ]
        ]);
    }
}
