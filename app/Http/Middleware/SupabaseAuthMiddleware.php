<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class SupabaseAuthMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $authHeader = $request->header('Authorization');

        if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            return response()->json(['error' => 'Unauthorized: Missing Bearer token'], 401);
        }

        $jwt = $matches[1];

        try {
            // ✅ Use config instead of env()
            $secret = config('services.supabase.jwt_secret');

            if (!$secret) {
                return response()->json(['error' => 'Supabase JWT secret not configured'], 500);
            }

            $decoded = JWT::decode($jwt, new Key($secret, 'HS256'));

            // Attach decoded user data
            $request->attributes->set('supabase_user', (array) $decoded);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Unauthorized: Invalid token',
                'details' => $e->getMessage(), // 👈 helpful for debugging
            ], 401);
        }

        return $next($request);
    }
}
