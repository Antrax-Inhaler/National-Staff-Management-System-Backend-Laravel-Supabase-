<?php

namespace App\Http\Controllers\v1;

use App\Enums\RoleEnum;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Member;
use App\Models\MemberOrganizationRole;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{

    public function checkUser(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        try {
            $user = User::where('email', $request->email)
                ->first();
        } catch (QueryException $e) {
            Log::error($e->getMessage());
            return response()->json([
                'success' => false,
                'exists' => false,
                'reason' => 'database_error',
                'message' => 'Unable to check user at the moment. Please try again later.',
            ], 500);
        }


        if (! $user) {
            return response()->json([
                "success" => false,
                'exists' => false,
                'reason' => 'user_not_found',
                'message' => "Invalid Email Address",
            ]);
        }

        $hasAffiliate = $user->member?->affiliate_id;
        $hasOrganizationRole = $user->hasRole(RoleEnum::nationalRoles());

        if (! $hasAffiliate && ! $hasOrganizationRole) {
            return response()->json([
                'exists' => false,
                'reason' => 'no_assigned_affiliate',
                'message' => "You are not linked to any affiliate. Please contact support."
            ]);
        }

        return response()->json([
            'exists' => true,
            'reason' => 'ok',
            'user_id' => $user->id,
            'message' => 'User exists and is eligible',
        ]);
    }


    public function getUserRolesAndPermissions()
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json([
                    "error" => "User not Found"
                ]);
            }

            // Get member record
            $member = Member::with('currentPositions.position')->where('user_id', $user->id)->first();
            if (!$member) {
                return response()->json([
                    'error' => 'Member not Found'
                ]);
            }
            $currentPositions = $member->currentPositions->pluck('position.name')->toArray();
            // $positionName = $currentOfficer?->position?->name ?? null;
            $display_name = $member ? $member->first_name : $user->email;

            $user = User::with('roles.permissions', 'permissions')->find($user->id);
            $roles = $user->roles->pluck('name')->toArray();
            $rolePermissions = $user->roles
                ->flatMap(fn($role) => $role->permissions)
                ->pluck('name')
                ->unique()
                ->toArray();

            $userPermissions = $user->permissions->pluck('name')->toArray();
            $permissions = array_unique(array_merge($rolePermissions, $userPermissions));


            return response()->json([
                'display_name' => $display_name,
                "roles" => $roles,
                "permissions" => $permissions,
                "position" => $currentPositions,
                "affiliate_id" => $member->affiliate_id,
                "affiliate_name" => $member->affiliate?->name,
                "affiliate_uid" => $member->affiliate?->public_uid
            ]);
        } catch (\Throwable $th) {
            throw $th;
        }
    }

    public function sync(Request $request)
    {
        $token = $request->input('access_token');
        $expires_in = $request->input('expires_in', 3600);
        // For cross-site cookies, you MUST use SameSite=None with Secure=true
        // This means you need HTTPS even in development
        $isProduction = app()->environment('production');

        return response(['message' => 'Synced'])
            ->cookie(
                'access_token',
                $token,
                $expires_in,           // minutes
                '/',                   // path
                null,                  // domain (null = current domain)
                true,                  // secure (HTTPS required for SameSite=None)
                true,                  // httpOnly
                false,                 // raw
                'None'                 // SameSite=None for cross-site
            );
    }
}
