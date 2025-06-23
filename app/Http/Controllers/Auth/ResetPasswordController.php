<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;
use Illuminate\Auth\Events\PasswordReset;

class ResetPasswordController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email|exists:users,email',
            'password' => 'required|min:8|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password)
                ])->setRememberToken(Str::random(60));

                $user->save();
                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            // Optionally generate API token for immediate login
            $user = User::where('email', $request->email)->first();
            $token = $user->createToken('password-reset')->plainTextToken;

            return response()->json([
                'message' => 'Password has been reset successfully.',
                'success' => true,
                'token' => $token
            ], 200);
        }

        return response()->json([
            'message' => 'Failed to reset password',
            'success' => false,
            'errors' => ['email' => [__($status)]]
        ], 422);
    }
}
