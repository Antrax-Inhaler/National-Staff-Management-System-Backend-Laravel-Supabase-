<?php

namespace App\Http\Middleware;

use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class VerifySupabaseToken
{
    public function handle($request, Closure $next)
    {
        $authHeader = $request->header('Authorization');

        // Check for Authorization header
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $jwt = substr($authHeader, 7); // remove "Bearer "

        try {
            // Decode token using Supabase JWT secret (HS256)
            $decoded = JWT::decode($jwt, new Key(env('SUPABASE_JWT_SECRET'), 'HS256'));

            // Attach decoded payload (user claims) to the request
            $request->merge([
                'supabase_user' => (array) $decoded,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Unauthorized',
                'error'   => $e->getMessage(),
            ], 401);
        }

        return $next($request);
    }
}
