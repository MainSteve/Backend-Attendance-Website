<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\AttendanceController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Models\Department;
use App\Models\User;

// Public routes
Route::post('/login', [AuthController::class, 'login']);

Route::get('/debug-user', function () {
    $user = User::find(1); // Use the ID from your example
    dd([
        'has_department_method' => method_exists($user, 'department'),
        'department_id_value' => $user->department_id,
        'department_exists' => Department::find($user->department_id) !== null,
        'relationship_loaded' => $user->relationLoaded('department'),
        'department_data' => $user->department
    ]);
});

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
