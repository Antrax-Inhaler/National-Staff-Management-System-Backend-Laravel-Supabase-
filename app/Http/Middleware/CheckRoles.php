<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Models\User;
use App\Models\Member;

class CheckRoles
{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $authHeader = $request->header('Authorization');
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $jwt = substr($authHeader, 7);

        try {
            // Verify Supabase JWT
            $decoded = JWT::decode($jwt, new Key(env('SUPABASE_JWT_SECRET'), 'HS256'));
            $user = User::where('email', $decoded->email)->first();

            if (!$user) {
                return response()->json(['error' => 'User not found'], 404);
            }

            // Collect roles
            $rolesList = [];
            if ($user->member) {
                foreach ($user->member->nationalRoles as $role) {
                    $rolesList[] = $role->name;
                }
                foreach ($user->member->affiliateOfficers as $officer) {
                    $rolesList[] = $officer->position->name;
                }
            }

            $request->merge(['user' => [
                'id' => $user->id,
                'email' => $user->email,
                'roles' => $rolesList
            ]]);

            // If middleware specified roles, check access
            if (!empty($roles) && count(array_intersect($roles, $rolesList)) === 0) {
                return response()->json(['error' => 'Forbidden'], 403);
            }

        } catch (\Exception $e) {
            return response()->json(['error' => 'Invalid token'], 401);
        }

        return $next($request);
    }
}
