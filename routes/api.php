<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\AttendanceController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;

// Public routes
Route::post('/login', [AuthController::class, 'login']);


// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth routes
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    // Regular employee routes
    Route::get('/departments', [DepartmentController::class, 'index']);
    // Get all attendance records for the authenticated employee
    Route::get('/attendance', [AttendanceController::class, 'index']);

    // Get a specific attendance record
    Route::get('/attendance/{id}', [AttendanceController::class, 'show']);

    // Create a new attendance record (clock in/out)
    Route::post('/attendance', [AttendanceController::class, 'store']);

    // Get today's attendance for the authenticated employee
    Route::get('/attendance/today', [AttendanceController::class, 'today']);

    // Get attendance records for a date range
    Route::post('/attendance/report', [AttendanceController::class, 'report']);

    // Admin only routes
    Route::middleware('role:admin')->group(function () {
        Route::apiResource('departments', DepartmentController::class)->except(['index']);
        Route::apiResource('users', UserController::class);
    });
});
