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
    
    // Admin only routes
    Route::middleware('role:admin')->group(function () {
        Route::apiResource('departments', DepartmentController::class)->except(['index']);
        Route::apiResource('users', UserController::class);
    });
});
