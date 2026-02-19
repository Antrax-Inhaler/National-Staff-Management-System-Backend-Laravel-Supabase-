<?php

namespace App\Http\Middleware;

use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class SupabaseAuth
{
    public function handle($request, Closure $next)
    {
        $authHeader = $request->header('Authorization');

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return response()->json(['error' => 'Unauthorized: Missing Bearer token'], 401);
        }

        $jwt = substr($authHeader, 7);

        try {
            $secret = env('SUPABASE_JWT_SECRET');

            // ✅ Decode with HS256 using your secret
            $decoded = JWT::decode($jwt, new Key($secret, 'HS256'));

            // Attach user info to request
            $request->attributes->add(['supabase_user' => (array) $decoded]);

            return $next($request);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Invalid or expired token',
                'details' => $e->getMessage()
            ], 401);
        }
    }
}
