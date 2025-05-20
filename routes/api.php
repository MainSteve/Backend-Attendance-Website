<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\WorkingHourController;
use App\Http\Controllers\HolidayController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use Illuminate\Auth\Events\Registered;
use App\Http\Controllers\Auth\RegisteredUserController;

// Public routes
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [RegisteredUserController::class, 'store']);
Route::get('/departments', [DepartmentController::class, 'index']);


// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth routes
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    // Regular employee routes
    
    // IMPORTANT: Order matters here - more specific routes must come first!
    
    // Get today's attendance for the authenticated employee
    Route::get('/attendance/today', [AttendanceController::class, 'today']);
    // Get attendance records for a date range
    Route::get('/attendance/report', [AttendanceController::class, 'report']);
    // Get all attendance records for the authenticated employee
    Route::get('/attendance', [AttendanceController::class, 'index']);
    // Get a specific attendance record
    Route::get('/attendance/{id}', [AttendanceController::class, 'show']);
    // Create a new attendance record (clock in/out)
    Route::post('/attendance', [AttendanceController::class, 'store']);
    // Add a task log to an attendance record
    Route::post('/attendance/{attendance}/task-logs', [AttendanceController::class, 'addTaskLog']);

    // Working hours routes (available to all authenticated users for viewing)
    Route::get('/working-hours/user/{userId}', [WorkingHourController::class, 'getForUser']);
    
    // Holidays routes (available to all authenticated users for viewing)
    Route::get('/holidays', [HolidayController::class, 'index']);
    Route::get('/holidays/{id}', [HolidayController::class, 'show']);
    
    // Admin only routes
    Route::middleware('role:admin')->group(function () {
        Route::apiResource('departments', DepartmentController::class)->except(['index']);
        Route::apiResource('users', UserController::class);
        
        // Admin-only working hours routes
        Route::get('/working-hours', [WorkingHourController::class, 'index']);
        Route::post('/working-hours', [WorkingHourController::class, 'store']);
        Route::patch('/working-hours/user/{userId}', [WorkingHourController::class, 'updateForUser']);
        Route::delete('/working-hours/{id}', [WorkingHourController::class, 'destroy']);
        
        // Admin-only holidays routes
        Route::post('/holidays', [HolidayController::class, 'store']);
        Route::put('/holidays/{id}', [HolidayController::class, 'update']);
        Route::patch('/holidays/{id}', [HolidayController::class, 'update']);
        Route::delete('/holidays/{id}', [HolidayController::class, 'destroy']);
        Route::post('/holidays/process-conflicts', [HolidayController::class, 'processConflicts']);
    });
});
