<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;

class RegisteredUserController extends Controller
{
    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): Response
    {
        $request->validate([
            'id' => 'required|numeric|max:99999999999999999|unique:users',
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'role' => ['sometimes', 'string', 'in:admin,employee'], // Add validation for role
            'position' => ['nullable', 'string', 'max:255'], // Add validation for position
            'department_id' => ['nullable', 'exists:departments,id'], // Add validation for department_id
        ]);

        $user = User::create([
            'id' => $request->id,
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->string('password')),
            'role' => $request->role ?? 'employee', // Default to 'employee' if not provided
            'position' => $request->position, // Nullable
            'department_id' => $request->department_id, // Nullable
        ]);

        event(new Registered($user));

        Auth::login($user);

        return response()->noContent();
    }
}
