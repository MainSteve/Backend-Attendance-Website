<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\QrToken;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class QrCodeController extends Controller
{
    /**
     * Generate a new QR code token
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function generate(Request $request)
    {
        // Check if user is admin
        if (Auth::user()->role !== 'admin') {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized. Only admins can generate QR codes.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'clock_type' => 'required|in:in,out',
            'location' => 'required|string|max:255',
            'expiry_minutes' => 'nullable|integer|min:1|max:1440', // Optional expiry in minutes (default: 10)
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Generate a unique token
        $token = Str::random(32);

        // Set expiry time (default: 10 minutes)
        $expiryMinutes = $request->input('expiry_minutes', 10);
        $expiresAt = Carbon::now()->addMinutes($expiryMinutes);

        // Create QR token record
        QrToken::create([
            'token' => $token,
            'clock_type' => $request->clock_type,
            'location' => $request->location,
            'is_used' => false,
            'expires_at' => $expiresAt,
            'created_by' => Auth::id()
        ]);

        // Generate the URL that will be encoded in the QR code
        // This should point to your FRONTEND, not backend
        $frontendUrl = config('app.frontend_url');
        $qrUrl = "{$frontendUrl}/qr-scan?token={$token}";

        return response()->json([
            'status' => true,
            'message' => 'QR code generated successfully',
            'data' => [
                'token' => $token,
                'qr_url' => $qrUrl,
                'expires_at' => $expiresAt->toDateTimeString(),
                'expires_in_minutes' => $expiryMinutes
            ]
        ]);
    }

    /**
     * Process QR code scan (REQUIRES AUTHENTICATION)
     *
     * @param string $token
     * @return \Illuminate\Http\JsonResponse
     */
    public function process($token)
    {
        // Find the token
        $qrToken = QrToken::where('token', $token)->first();

        if (!$qrToken) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid QR code'
            ], 404);
        }

        // Check if token is already used
        if ($qrToken->is_used) {
            return response()->json([
                'status' => false,
                'message' => 'QR code has already been used'
            ], 400);
        }

        // Check if token is expired
        if (Carbon::now()->isAfter($qrToken->expires_at)) {
            return response()->json([
                'status' => false,
                'message' => 'QR code has expired'
            ], 400);
        }

        // Mark token as used
        $qrToken->is_used = true;
        $qrToken->save();

        // Call the attendance controller's store method
        $attendanceController = new AttendanceController();

        // Create a new request with the necessary data
        $attendanceRequest = new Request([
            'clock_type' => $qrToken->clock_type,
            'location' => $qrToken->location,
            'method' => 'qr_code'
        ]);

        // Process the attendance (now with authenticated user)
        return $attendanceController->store($attendanceRequest);
    }
}
