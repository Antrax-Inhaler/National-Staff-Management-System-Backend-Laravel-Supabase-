<?php

namespace App\Http\Middleware;

use App\Enums\RoleEnum;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {

        $user = Auth::user();

        try {
            $roleEnums = array_map(
                fn (string $role) => RoleEnum::from($role),
                $roles
            );
            
            // return response()->json([
            //     $roleEnums
            // ]);

        } catch (\ValueError) {
            return response()->json([
                'message' => 'Invalid role configured on route'
            ], 500);
        }

        if (! $user->hasRole($roleEnums)) {
            return response()->json([
                'message' => 'Forbidden'
            ], 403);
        }

        return $next($request);
    }
}
