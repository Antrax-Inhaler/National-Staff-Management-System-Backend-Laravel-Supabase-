<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForceCors
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next)
    {
        $origin = $request->header('Origin');

        // Get allowed origins from your existing config
        $allowedOrigins = config('cors.allowed_origins', []);

        // Check if this origin is allowed
        $isAllowed = in_array($origin, $allowedOrigins) || in_array('*', $allowedOrigins);
        $allowedOrigin = $isAllowed ? $origin : null;

        // Handle OPTIONS preflight
        if ($request->isMethod('OPTIONS')) {
            $response = response('', 204);

            if ($allowedOrigin) {
                $response->header('Access-Control-Allow-Origin', $allowedOrigin)
                    ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS')
                    ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin')
                    ->header('Access-Control-Allow-Credentials', 'true')
                    ->header('Access-Control-Max-Age', '86400');
            }

            return $response;
        }

        $response = $next($request);

        // Only add headers if origin is allowed
        if ($allowedOrigin) {
            $response->headers->set('Access-Control-Allow-Origin', $allowedOrigin);
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin');
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }
}
