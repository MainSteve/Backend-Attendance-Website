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
use App\Http\Controllers\AnnouncementController;

// Public routes
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [RegisteredUserController::class, 'store']);
Route::get('/departments', [DepartmentController::class, 'index']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth routes
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    // Upload/Update photo profile
    Route::post('/users/{userId}/photo-profile', [UserController::class, 'uploadPhotoProfile'])
        ->name('users.photo-profile.upload');

    // Get photo profile URL
    Route::get('/users/{userId}/photo-profile', [UserController::class, 'getPhotoProfile'])
        ->name('users.photo-profile.show');

    // Delete photo profile
    Route::delete('/users/{userId}/photo-profile', [UserController::class, 'deletePhotoProfile'])
        ->name('users.photo-profile.delete');

    // User profile routes (accessible by all authenticated users for their own profile)
    Route::get('/profile', [UserController::class, 'getProfile'])
        ->name('profile.show');
    
    Route::put('/profile', [UserController::class, 'updateProfile'])
        ->name('profile.update');

    // Regular employee routes

    // IMPORTANT: Order matters here - more specific routes must come first!

    Route::prefix('attendance')->group(function () {

        // Basic Attendance Routes
        Route::get('/', [AttendanceController::class, 'index'])->name('attendance.index');
        Route::post('/', [AttendanceController::class, 'store'])->name('attendance.store');
        Route::get('/latest', [AttendanceController::class, 'latest'])->name('attendance.latest');
        Route::get('/today', [AttendanceController::class, 'today'])->name('attendance.today');
        Route::get('/report', [AttendanceController::class, 'report'])->name('attendance.report');
        Route::get('/{id}', [AttendanceController::class, 'show'])->name('attendance.show');

        // Task Log Management
        Route::post('/{attendance}/task-log', [AttendanceController::class, 'addTaskLog'])
            ->name('attendance.task-log.store');

        // Task Log CRUD Operations
        // Update task log (description and/or photo)
        Route::put('/task-log/{taskLogId}', [AttendanceController::class, 'updateTaskLog'])
            ->name('attendance.task-log.update');

        // Alternative PATCH route for partial updates
        Route::patch('/task-log/{taskLogId}', [AttendanceController::class, 'updateTaskLog'])
            ->name('attendance.task-log.patch');

        // Delete task log and its photo
        Route::delete('/task-log/{taskLogId}', [AttendanceController::class, 'deleteTaskLog'])
            ->name('attendance.task-log.delete');
    });

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

    // Announcement routes for all authenticated users
    Route::get('/announcements', [AnnouncementController::class, 'index']);
    Route::get('/announcements/my-department', [AnnouncementController::class, 'myDepartmentAnnouncements']);
    Route::get('/announcements/{announcement}', [AnnouncementController::class, 'show']);

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
        Route::post('/qr-code/generate', [QrCodeController::class, 'generate']);

        // Admin-only announcement routes
        Route::post('/announcements', [AnnouncementController::class, 'store']); // POST with department_ids[]
        Route::put('/announcements/{announcement}', [AnnouncementController::class, 'update']); // PUT with department_ids[]
        Route::patch('/announcements/{announcement}', [AnnouncementController::class, 'update']); // PATCH with department_ids[]
        Route::delete('/announcements/{announcement}', [AnnouncementController::class, 'destroy']);
        Route::post('/announcements/{announcement}/toggle-active', [AnnouncementController::class, 'toggleActive']);
        Route::get('/announcements-statistics', [AnnouncementController::class, 'statistics']);
    });
});
