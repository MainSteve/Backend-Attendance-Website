<?php

namespace App\Http\Controllers;

use App\Models\Holiday;
use App\Models\WorkingHour;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class HolidayController extends Controller
{
    /**
     * Display a listing of holidays with filtering.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $query = Holiday::query();

        // Filter by date range
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('date', [$request->start_date, $request->end_date]);
        } elseif ($request->has('year')) {
            $year = $request->year;
            $query->where(function($q) use ($year) {
                $q->whereYear('date', $year)
                  ->orWhere('is_recurring', true);
            });
        } else {
            // Default to current year if no filter specified
            $currentYear = Carbon::now()->year;
            $query->where(function($q) use ($currentYear) {
                $q->whereYear('date', $currentYear)
                  ->orWhere('is_recurring', true);
            });
        }

        // Filter by is_recurring
        if ($request->has('is_recurring')) {
            $query->where('is_recurring', $request->boolean('is_recurring'));
        }

        $perPage = $request->query('per_page', 15);
        $holidays = $query->orderBy('date')->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Holidays retrieved successfully',
            'data' => $holidays
        ]);
    }

    /**
     * Store a newly created holiday.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'date' => 'required|date',
            'description' => 'nullable|string',
            'is_recurring' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $holiday = Holiday::create([
            'name' => $request->name,
            'date' => $request->date,
            'description' => $request->description,
            'is_recurring' => $request->input('is_recurring', false),
        ]);

        // Check for working hours conflicts
        $affectedWorkingHours = $this->getAffectedWorkingHours($holiday);

        return response()->json([
            'status' => true,
            'message' => 'Holiday created successfully',
            'data' => [
                'holiday' => $holiday,
                'affected_working_hours' => $affectedWorkingHours
            ]
        ], 201);
    }

    /**
     * Display the specified holiday.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $holiday = Holiday::find($id);
        
        if (!$holiday) {
            return response()->json([
                'status' => false,
                'message' => 'Holiday not found'
            ], 404);
        }

        // Get affected working hours for this holiday
        $affectedWorkingHours = $this->getAffectedWorkingHours($holiday);

        return response()->json([
            'status' => true,
            'message' => 'Holiday retrieved successfully',
            'data' => [
                'holiday' => $holiday,
                'affected_working_hours' => $affectedWorkingHours
            ]
        ]);
    }

    /**
     * Update the specified holiday.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $holiday = Holiday::find($id);
        
        if (!$holiday) {
            return response()->json([
                'status' => false,
                'message' => 'Holiday not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'date' => 'sometimes|required|date',
            'description' => 'nullable|string',
            'is_recurring' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Store old date for comparison
        $oldDate = $holiday->date;
        
        $holiday->update([
            'name' => $request->input('name', $holiday->name),
            'date' => $request->input('date', $holiday->date),
            'description' => $request->input('description', $holiday->description),
            'is_recurring' => $request->input('is_recurring', $holiday->is_recurring),
        ]);

        // If date or recurring status changed, check for working hours conflicts
        $affectedWorkingHours = null;
        if ($oldDate != $holiday->date || $request->has('is_recurring')) {
            $affectedWorkingHours = $this->getAffectedWorkingHours($holiday);
        }

        return response()->json([
            'status' => true,
            'message' => 'Holiday updated successfully',
            'data' => [
                'holiday' => $holiday,
                'affected_working_hours' => $affectedWorkingHours
            ]
        ]);
    }

    /**
     * Remove the specified holiday.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $holiday = Holiday::find($id);
        
        if (!$holiday) {
            return response()->json([
                'status' => false,
                'message' => 'Holiday not found'
            ], 404);
        }

        $holiday->delete();

        return response()->json([
            'status' => true,
            'message' => 'Holiday deleted successfully'
        ]);
    }

    /**
     * Get working hours affected by a holiday
     * 
     * @param Holiday $holiday
     * @return array
     */
    private function getAffectedWorkingHours(Holiday $holiday)
    {
        $date = Carbon::parse($holiday->date);
        $dayOfWeek = strtolower($date->format('l'));
        
        // Get all working hours for the day of week
        $workingHours = WorkingHour::with('user')
            ->where('day_of_week', $dayOfWeek)
            ->get();
            
        $users = [];
        foreach ($workingHours as $workingHour) {
            $users[] = [
                'user_id' => $workingHour->user_id,
                'name' => $workingHour->user->name,
                'working_hour_id' => $workingHour->id,
                'start_time' => $workingHour->start_time->format('H:i'),
                'end_time' => $workingHour->end_time->format('H:i'),
            ];
        }
        
        return [
            'date' => $date->toDateString(),
            'day_of_week' => $dayOfWeek,
            'user_count' => count($users),
            'users' => $users
        ];
    }

    /**
     * Process conflicting working hours
     * 
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function processConflicts(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'holiday_id' => 'required|exists:holidays,id',
            'action' => 'required|in:skip,delete',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $holiday = Holiday::find($request->holiday_id);
        if (!$holiday) {
            return response()->json([
                'status' => false,
                'message' => 'Holiday not found'
            ], 404);
        }

        $date = Carbon::parse($holiday->date);
        $dayOfWeek = strtolower($date->format('l'));
        
        // Get all working hours for the day of week
        $workingHours = WorkingHour::where('day_of_week', $dayOfWeek);
        
        $action = $request->action;
        $affected = $workingHours->count();
        
        if ($action === 'delete' && $affected > 0) {
            // Delete the working hours
            $workingHours->delete();
            
            return response()->json([
                'status' => true,
                'message' => "$affected working hour records deleted successfully",
                'data' => [
                    'holiday' => $holiday,
                    'affected_count' => $affected
                ]
            ]);
        }
        
        return response()->json([
            'status' => true,
            'message' => "No changes made to working hours",
            'data' => [
                'holiday' => $holiday,
                'affected_count' => $affected,
                'action' => $action
            ]
        ]);
    }
}
