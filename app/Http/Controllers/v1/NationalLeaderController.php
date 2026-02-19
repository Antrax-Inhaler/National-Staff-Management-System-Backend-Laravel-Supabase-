<?php

namespace App\Http\Controllers\v1;

use App\Enums\RoleEnum;
use App\Http\Controllers\Controller;
use App\Http\Resources\MemberResource;
use App\Models\ActivityLog;
use App\Models\Member;
use App\Models\OrganizationRole;
use App\Models\OfficerHistory;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OrganizationLeaderController extends Controller
{

    public function index(Request $request)
    {

        $query = Member::query()->with([
            'currentPosition.position:id,name',
            'affiliate:id,name',
            'user:id,email',
            'user.roles' => function ($query) {
                $query->whereNotIn('name', [RoleEnum::AFFILIATE_MEMBER, RoleEnum::AFFILIATE_OFFICER]); // exclude affiliate_member
            },
        ]);

        $query->whereHas("user", function ($query) use ($request) {
            $query->whereHas("roles", function ($query) use ($request) {
                $query->where("name", RoleEnum::NATIONAL_ADMINISTRATOR);
            });
        });

        $query->when($request->query('search'), fn($q, $search) => $q->search($search));

        $perPage = $request->query('per_page', 15);

        // For large exports, return all data without pagination
        if ($perPage == 'All') {
            $members = $query->get();
            return response()->json([
                'success' => true,
                'data' => MemberResource::collection($members),
                'meta' => [
                    'total' => $members->count(),
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => $members->count(),
                ]
            ]);
        }

        $perPage = $perPage === 'All' ? 15 : (int) $perPage;
        $paginated = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => MemberResource::collection($paginated->items()),
            'meta' => [
                'total' => $paginated->total(),
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
            ]
        ]);
    }

    public function roles()
    {
        $roles = Role::whereNotIn('name', [RoleEnum::AFFILIATE_MEMBER, RoleEnum::AFFILIATE_OFFICER])
            ->whereNull('parent_role_id')
            ->whereNotNull('order')
            ->with('children')
            ->orderBy('order')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $roles
        ]);
    }

    public function createRole(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'description' => 'required|string',
        ]);

        // Convert name to snake_case
        $validated['name'] = Str::snake($validated['name']);

        try {
            $role = Role::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'Role created successfully.',
                'data' => $role,
            ], 201);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create role.',
                'error' => config('app.debug') ? $th->getMessage() : null,
            ], 500);
        }
    }

    public function detachRole(Request $request)
    {
        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'role_id' => ['required', 'exists:roles,id'],
            'national' => ['nullable'], // if needed
        ]);

        try {
            // Remove from user_role pivot
            $deleted = UserRole::where('user_id', $validated['user_id'])
                ->where('role_id', $validated['role_id'])
                ->delete();

            // Find the active role history entry
            $roleHistory = OfficerHistory::where('user_id', $validated['user_id'])
                ->where('entity_id', $validated['role_id'])
                ->whereNull('end_date')
                ->first();

            if ($roleHistory) {
                $roleHistory->update([
                    'end_date' => now(),
                ]);
            }

            if ($deleted) {

                $user_name = User::where('id', $validated['user_id'])->value('name');
                $role = Role::find($validated['role_id']);

                $new_values = [
                    $user_name => $role->name,
                ];

                $old_values = [
                    $user_name => 'removed'
                ];

                ActivityLog::create([
                    'user_id'        => Auth::id(),
                    'affiliate_id'   => null,
                    'action'         => 'removed',
                    'auditable_type' => Role::class,
                    'auditable_id'   => $role->id,
                    'auditable_name' => $user_name ?? null,
                    'old_values'     => $old_values,
                    'new_values'     => $new_values,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Successfully removed user from role.',
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'No matching user-role found.',
            ], 404);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'error' => 'Something went wrong.',
                'details' => config('app.debug') ? $th->getMessage() : null,
            ], 500);
        }
    }


    public function assignRole(Request $request)
    {
        $validated = $request->validate([
            'role_id' => ['required', 'exists:roles,id'],
            'user_ids' => ['required', 'array'],
            'user_ids.*' => ['exists:users,id'],
        ]);


        try {
            $role = Role::find($validated['role_id']);

            DB::transaction(function () use ($validated, $role, $request) {
                $user = Auth::user();
                collect($validated['user_ids'])->each(function ($user_id) use ($role, $request) {
                    $userRole = UserRole::updateOrCreate([
                        'user_id' => $user_id,
                        'role_id' => $role->id,
                    ]);

                    if ($userRole->wasRecentlyCreated) {
                        $role->histories()->create([
                            'user_id' => $user_id,
                            'start_date' => now(),
                            'level' => 'national',
                        ]);
                    }

                    $user_name = User::where('id', $user_id)->value('name');
                    $role_name = $role->name;

                    $new_values = [
                        $user_name => $role->name,
                    ];

                    $old_values = [
                        $user_name => 'assigned'
                    ];

                    ActivityLog::create([
                        'user_id'        => Auth::id(),
                        'affiliate_id'   => null,
                        'action'         => 'assigned',
                        'auditable_type' => Role::class,
                        'auditable_id'   => $role->id,
                        'auditable_name' => $user_name ?? null,
                        'old_values'     => $old_values,
                        'new_values'     => $new_values,
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                    ]);
                });
            });


            return response()->json([
                'success' => true,
                'message' => 'Successfully assigned user to role.',
            ]);
        } catch (\Throwable $th) {
            Log::error('assign', [
                $th
            ]);
            return response()->json([
                'success' => false,
                'error' => 'Something went wrong.',
                'details' => config('app.debug') ? $th->getMessage() : null,
            ], 500);
        }
    }

    public function searchUser(Request $request)
    {
        $roleId = $request->query('role');

        $query = Member::select('id', 'user_id', 'first_name', 'last_name', 'member_id', 'affiliate_id')
            ->where(function ($q) use ($roleId) {
                $q->whereHas('user', function ($userQuery) use ($roleId) {
                    $userQuery->whereDoesntHave('roles', function ($roleQuery) use ($roleId) {
                        $roleQuery->where('roles.id', $roleId);
                    })
                        // Or user has no roles at all
                        ->orWhereDoesntHave('roles');
                });
            })
            ->with('affiliate')
            ->when($request->query('affiliate'), function ($q, $affiliate) {
                $q->where('affiliate_id', $affiliate);
            })
            ->when($request->query('search'), function ($q, $search) {
                $q->search($search);
            });



        $query->orderBy('last_name');
        $options = $query->limit(20)->get();

        return response()->json([
            'data' => $options
        ], 200);
    }

    public function leaders(Request $request)
    {
        $query = Member::query()->with([
            'currentPosition.position:id,name',
            'affiliate:id,name',
            'user:id,email',
            'user.roles' => function ($query) {
                $query->whereNotIn('name', [RoleEnum::AFFILIATE_MEMBER, RoleEnum::AFFILIATE_OFFICER]); // exclude affiliate_member
            },
        ]);

        $query->whereHas("user", function ($query) use ($request) {
            $query->whereHas("roles", function ($query) use ($request) {
                $query->where("roles.id", $request->query('role'));
            });
        });

        $query->when($request->query('search'), fn($q, $search) => $q->search($search));

        $perPage = $request->query('per_page', 15);

        // For large exports, return all data without pagination
        if ($perPage == 'All') {
            $members = $query->get();
            return response()->json([
                'success' => true,
                'data' => MemberResource::collection($members),
                'meta' => [
                    'total' => $members->count(),
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => $members->count(),
                ]
            ]);
        }

        $perPage = $perPage === 'All' ? 15 : (int) $perPage;
        $paginated = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => MemberResource::collection($paginated->items()),
            'meta' => [
                'total' => $paginated->total(),
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
            ]
        ]);
    }

    public function rolesOptions(Request $request)
    {
        $roles = Role::whereNotIn('name', [RoleEnum::AFFILIATE_MEMBER, RoleEnum::AFFILIATE_OFFICER])
            ->doesntHave('children')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $roles
        ]);
    }

    public function getHistory($id, Request $request)
    {

        try {
            $query = OfficerHistory::query()
                ->where('entity_id', $id)
                ->whereNotNull('end_date')
                ->where('entity_type', Role::class)
                ->with('entity', 'user');

            $perPage = $request->query('per_page', 15);

            // For large exports, return all data without pagination
            if ($perPage == 'All') {
                $members = $query->get();
                return response()->json([
                    'success' => true,
                    'data' => $members,
                    'meta' => [
                        'total' => $members->count(),
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => $members->count(),
                    ]
                ]);
            }

            $perPage = $perPage === 'All' ? 15 : (int) $perPage;
            $paginated = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $paginated->items(),
                'meta' => [
                    'total' => $paginated->total(),
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'per_page' => $paginated->perPage(),
                ]
            ]);
        } catch (\Throwable $th) {
            throw $th;
        }
    }
}