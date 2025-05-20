<?php

namespace App\Http\Controllers;

use App\Models\WorkingHour;
use App\Models\Holiday;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class WorkingHourController extends Controller
{
    /**
     * Display a listing of working hours with filtering.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $query = WorkingHour::with('user');

        // Filter by user_id
        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Filter by day of week
        if ($request->has('day_of_week')) {
            $query->where('day_of_week', strtolower($request->day_of_week));
        }

        // Filter by start time range
        if ($request->has('min_start_time')) {
            $query->where('start_time', '>=', $request->min_start_time);
        }

        if ($request->has('max_start_time')) {
            $query->where('start_time', '<=', $request->max_start_time);
        }

        $perPage = $request->query('per_page', 15);
        $workingHours = $query->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Working hours retrieved successfully',
            'data' => $workingHours
        ]);
    }

    /**
     * Store new working hours for multiple users
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'users' => 'required|array|min:1',
            'users.*' => 'required|exists:users,id',
            'schedules' => 'required|array|min:1',
            'schedules.*.day_of_week' => [
                'required',
                Rule::in(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']),
            ],
            'schedules.*.start_time' => 'required|date_format:H:i',
            'schedules.*.end_time' => 'required|date_format:H:i|after:schedules.*.start_time',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $users = $request->users;
        $schedules = $request->schedules;
        $createdRecords = [];

        // Check for holiday conflicts with the scheduled days
        $shouldCheckHolidays = $request->input('check_holidays', true);
        $holidayDates = [];
        
        if ($shouldCheckHolidays) {
            // Get all holidays (both regular and recurring)
            $holidays = Holiday::all();
            
            // For each schedule day, check if it's a holiday
            foreach ($schedules as $schedule) {
                $dayOfWeek = strtolower($schedule['day_of_week']);
                
                // Get all dates in the current year that fall on the given day of week
                $year = Carbon::now()->year;
                $date = Carbon::create($year, 1, 1)->startOfWeek();
                
                // Move to the first occurrence of the given day in the year
                while ($date->format('l') !== ucfirst($dayOfWeek)) {
                    $date->addDay();
                }
                
                // Collect all dates for this day of week in the year
                $dates = [];
                while ($date->year === $year) {
                    $currentDate = $date->copy();
                    
                    // Check if this date is a holiday
                    $isHoliday = $holidays->contains(function ($holiday) use ($currentDate) {
                        if ($holiday->is_recurring) {
                            return $holiday->date->month === $currentDate->month &&
                                  $holiday->date->day === $currentDate->day;
                        } else {
                            return $holiday->date->isSameDay($currentDate);
                        }
                    });
                    
                    if ($isHoliday) {
                        $holidayDates[] = $currentDate->toDateString();
                    }
                    
                    $date->addWeek();
                }
            }
        }

        // Begin a database transaction
        DB::beginTransaction();

        try {
            foreach ($users as $userId) {
                foreach ($schedules as $schedule) {
                    // Check if a record already exists for this user and day
                    $existingRecord = WorkingHour::where('user_id', $userId)
                        ->where('day_of_week', $schedule['day_of_week'])
                        ->first();

                    if ($existingRecord) {
                        // Update existing record
                        $existingRecord->update([
                            'start_time' => $schedule['start_time'],
                            'end_time' => $schedule['end_time']
                        ]);
                        $createdRecords[] = $existingRecord;
                    } else {
                        // Create new record
                        $workingHour = WorkingHour::create([
                            'user_id' => $userId,
                            'day_of_week' => $schedule['day_of_week'],
                            'start_time' => $schedule['start_time'],
                            'end_time' => $schedule['end_time']
                        ]);
                        $createdRecords[] = $workingHour;
                    }
                }
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Working hours created successfully',
                'data' => [
                    'working_hours' => $createdRecords,
                    'holiday_conflicts' => $holidayDates
                ]
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => false,
                'message' => 'Failed to create working hours',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update working hours for a specific user
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $userId
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateForUser(Request $request, $userId)
    {
        // Check if user exists
        $user = User::find($userId);
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'schedules' => 'required|array|min:1',
            'schedules.*.day_of_week' => [
                'required',
                Rule::in(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']),
            ],
            'schedules.*.start_time' => 'required|date_format:H:i',
            'schedules.*.end_time' => 'required|date_format:H:i|after:schedules.*.start_time',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $schedules = $request->schedules;
        $updatedRecords = [];

        // Begin a database transaction
        DB::beginTransaction();

        try {
            // Option to completely replace the user's schedule
            if ($request->input('replace_all', false)) {
                WorkingHour::where('user_id', $userId)->delete();
            }

            foreach ($schedules as $schedule) {
                // Check if a record already exists for this user and day
                $existingRecord = WorkingHour::where('user_id', $userId)
                    ->where('day_of_week', $schedule['day_of_week'])
                    ->first();

                if ($existingRecord) {
                    // Update existing record
                    $existingRecord->update([
                        'start_time' => $schedule['start_time'],
                        'end_time' => $schedule['end_time']
                    ]);
                    $updatedRecords[] = $existingRecord;
                } else {
                    // Create new record
                    $workingHour = WorkingHour::create([
                        'user_id' => $userId,
                        'day_of_week' => $schedule['day_of_week'],
                        'start_time' => $schedule['start_time'],
                        'end_time' => $schedule['end_time']
                    ]);
                    $updatedRecords[] = $workingHour;
                }
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Working hours updated successfully',
                'data' => $updatedRecords
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => false,
                'message' => 'Failed to update working hours',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a specific working hour
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $workingHour = WorkingHour::find($id);
        
        if (!$workingHour) {
            return response()->json([
                'status' => false,
                'message' => 'Working hour not found'
            ], 404);
        }

        $workingHour->delete();

        return response()->json([
            'status' => true,
            'message' => 'Working hour deleted successfully'
        ]);
    }

    /**
     * Get working hours for a specific user
     *
     * @param  int  $userId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getForUser($userId)
    {
        // Check if user exists
        $user = User::find($userId);
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found'
            ], 404);
        }

        $workingHours = WorkingHour::where('user_id', $userId)
            ->orderBy('day_of_week', 'asc')
            ->get();

        // Format the data with the day of week as a key
        $formattedHours = [];
        $daysOrder = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        
        foreach ($daysOrder as $day) {
            $dayRecord = $workingHours->where('day_of_week', $day)->first();
            if ($dayRecord) {
                $formattedHours[$day] = [
                    'id' => $dayRecord->id,
                    'start_time' => $dayRecord->start_time->format('H:i'),
                    'end_time' => $dayRecord->end_time->format('H:i'),
                    'duration_minutes' => $dayRecord->duration
                ];
            } else {
                $formattedHours[$day] = null;
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Working hours retrieved successfully',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                ],
                'schedule' => $formattedHours
            ]
        ]);
    }
}
