<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\WorkingHourController;
use App\Http\Controllers\HolidayController;
use App\Http\Controllers\LeaveQuotaController;
use App\Http\Controllers\LeaveRequestController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use Illuminate\Auth\Events\Registered;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\QrCodeController;

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

    // Attendance routes
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

    // Leave quota routes
    Route::get('/leave-quotas', [LeaveQuotaController::class, 'index']);
    Route::get('/leave-summary', [LeaveRequestController::class, 'getQuotaSummary']);

    // Leave request routes
    Route::get('/leave-requests', [LeaveRequestController::class, 'index']);
    Route::post('/leave-requests', [LeaveRequestController::class, 'store']);
    Route::get('/leave-requests/{id}', [LeaveRequestController::class, 'show']);
    Route::post('/leave-requests/{id}/cancel', [LeaveRequestController::class, 'cancel']);

    Route::get('/attendance/qr/{token}', [QrCodeController::class, 'process']);

    // Admin only routes
    Route::middleware('role:admin')->group(function () {
        // Department and user management
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

        // Admin-only leave quota routes
        Route::post('/leave-quotas', [LeaveQuotaController::class, 'store']);
        Route::get('/leave-quotas/{id}', [LeaveQuotaController::class, 'show']);
        Route::put('/leave-quotas/{id}', [LeaveQuotaController::class, 'update']);
        Route::post('/leave-quotas/generate', [LeaveQuotaController::class, 'generateYearlyQuotas']);

        // Admin-only leave request routes
        Route::post('/leave-requests/{id}/status', [LeaveRequestController::class, 'updateStatus']);
        Route::delete('/leave-requests/{id}', [LeaveRequestController::class, 'destroy']);

        // QR code generation route
        Route::post('/qrcode/generate', [QrCodeController::class, 'generate']);
    });
});
