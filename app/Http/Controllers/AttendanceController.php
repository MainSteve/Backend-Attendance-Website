<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'clock_type' => 'required|in:in,out',
            'location' => 'nullable|string',
            'method' => 'required|in:qr,tasklog',
        ]);

        $attendance = new Attendance($validated);
        $attendance->user_id = $request->user()->id;
        $attendance->save();

        return response()->json($attendance, 201);
    }

    public function userAttendances(Request $request)
    {
        $attendances = Attendance::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->get();
            
        return response()->json($attendances);
    }
}
