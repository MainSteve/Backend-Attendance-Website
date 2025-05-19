<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter as FacadesRateLimiter;
use Symfony\Component\HttpFoundation\Response;

class ThrottleApi
{
    public function handle(Request $request, Closure $next, int $maxAttempts = 60, int $decayMinutes = 1): Response
    {
        $key = 'api:' . ($request->user()?->id ?: $request->ip());
        
        if (FacadesRateLimiter::tooManyAttempts($key, $maxAttempts)) {
            return response()->json([
                'message' => 'Too many requests',
                'retry_after' => FacadesRateLimiter::availableIn($key)
            ], 429);
        }
        
        FacadesRateLimiter::hit($key, $decayMinutes * 60);
        
        $response = $next($request);
        
        $response->headers->set(
            'X-RateLimit-Limit', $maxAttempts,
        );
        
        $response->headers->set(
            'X-RateLimit-Remaining', FacadesRateLimiter::remaining($key, $maxAttempts),
        );
        
        return $response;
    }
}
