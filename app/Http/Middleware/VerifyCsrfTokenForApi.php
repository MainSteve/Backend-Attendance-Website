<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfTokenForApi extends Middleware
{
    /**
     * Handle an incoming request.
     */
    public function handle($request, Closure $next)
    {
        // Skip CSRF verification for API routes
        if ($request->is('api/*')) {
            return $next($request);
        }
        
        return parent::handle($request, $next);
    }
}
