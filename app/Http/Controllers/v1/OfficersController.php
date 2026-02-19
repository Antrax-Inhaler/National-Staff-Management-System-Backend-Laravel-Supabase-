<?php

namespace App\Http\Controllers\v1;

use App\Enums\RoleEnum;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Affiliate;
use App\Models\AffiliateOfficer;
use App\Models\Member;
use App\Models\OfficerPosition;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OfficersController extends Controller
{

    // Fetch Officers in the current logged in Affiliate
    public function fetchOfficers(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);
            $affiliate_id = Member::where('user_id', Auth::id())->first()->affiliate_id;

            $query = OfficerPosition::with([
                'primaryOfficer' => function ($q) use ($affiliate_id) {
                    $q->where('affiliate_id', $affiliate_id)
                        ->select('id', 'affiliate_id', 'position_id', 'member_id', 'start_date', 'end_date', 'is_vacant')
                        ->with([
                            'member:id,first_name,last_name',
                            'member.currentPositions' => function ($query) use ($affiliate_id) {
                                $query->where('affiliate_id', $affiliate_id)
                                    ->where('is_vacant', false)
                                    ->where(function ($q) {
                                        $q->whereNull('end_date')
                                            ->orWhere('end_date', '>', now());
                                    })
                                    ->select('id', 'position_id', 'member_id', 'start_date')
                                    ->with('position:id,name');
                            }
                        ]);
                },
                'secondaryOfficer' => function ($q) use ($affiliate_id) {
                    $q->where('affiliate_id', $affiliate_id)
                        ->select('id', 'affiliate_id', 'position_id', 'member_id', 'start_date', 'end_date', 'is_vacant')
                        ->with([
                            'member:id,first_name,last_name',
                            'member.currentPositions' => function ($query) use ($affiliate_id) {
                                $query->where('affiliate_id', $affiliate_id)
                                    ->where('is_vacant', false)
                                    ->where(function ($q) {
                                        $q->whereNull('end_date')
                                            ->orWhere('end_date', '>', now());
                                    })
                                    ->select('id', 'position_id', 'member_id', 'start_date')
                                    ->with('position:id,name');
                            }
                        ]);
                },
                'previousOfficers' => function ($query) use ($affiliate_id) {
                    $query->where('is_vacant', 0)
                        ->where('affiliate_id', $affiliate_id)
                        ->where('end_date', '<=', now())
                        ->whereHas('member')
                        ->with('member:id,first_name,last_name')
                        ->orderBy('end_date', 'desc')
                        ->limit(3);
                }
            ])
                ->orderBy('display_order');

            if ($perPage == "All") {
                $positions = $query->get();
                return response()->json([
                    'success' => true,
                    'data' => $positions,
                    'meta' => [
                        'total' => $positions->count(),
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => $positions->count(),
                    ]
                ]);
            }

            $positions = $query->paginate(20);
            return response()->json($positions);
        } catch (\Throwable $th) {
            throw $th;
        }
    }
    public function affiliateOfficers(Request $request, $id)
    {
        try {
            $affiliate_id = Affiliate::where('public_uid', $id)->value('id');
            $perPage = $request->input('per_page', 10);

            $query = OfficerPosition::with([
                'primaryOfficer' => function ($q) use ($affiliate_id) {
                    $q->where('affiliate_id', $affiliate_id)
                        ->select('id', 'affiliate_id', 'position_id', 'member_id', 'start_date', 'end_date', 'is_vacant')
                        ->with([
                            'member:id,first_name,last_name',
                            'member.currentPositions' => function ($query) use ($affiliate_id) {
                                $query->where('affiliate_id', $affiliate_id)
                                    ->where('is_vacant', false)
                                    ->where(function ($q) {
                                        $q->whereNull('end_date')
                                            ->orWhere('end_date', '>', now());
                                    })
                                    ->select('id', 'position_id', 'member_id', 'start_date')
                                    ->with('position:id,name');
                            }
                        ]);
                },
                'secondaryOfficer' => function ($q) use ($affiliate_id) {
                    $q->where('affiliate_id', $affiliate_id)
                        ->select('id', 'affiliate_id', 'position_id', 'member_id', 'start_date', 'end_date', 'is_vacant')
                        ->with([
                            'member:id,first_name,last_name',
                            'member.currentPositions' => function ($query) use ($affiliate_id) {
                                $query->where('affiliate_id', $affiliate_id)
                                    ->where('is_vacant', false)
                                    ->where(function ($q) {
                                        $q->whereNull('end_date')
                                            ->orWhere('end_date', '>', now());
                                    })
                                    ->select('id', 'position_id', 'member_id', 'start_date')
                                    ->with('position:id,name');
                            }
                        ]);
                },
                'previousOfficers' => function ($query) use ($affiliate_id) {
                    $query->where('is_vacant', 0)
                        ->where('affiliate_id', $affiliate_id)
                        ->where('end_date', '<=', now())
                        ->whereHas('member')
                        ->with('member:id,first_name,last_name')
                        ->orderBy('end_date', 'desc')
                        ->limit(3);
                }
            ])
                ->orderBy('display_order');

            if ($perPage == "All") {
                $positions = $query->get();
                return response()->json([
                    'success' => true,
                    'data' => $positions,
                    'meta' => [
                        'total' => $positions->count(),
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => $positions->count(),
                    ]
                ]);
            }

            $positions = $query->paginate(20);
            return response()->json($positions);
        } catch (\Throwable $th) {
            throw $th;
        }
    }
    public function noPositions(Request $request)
    {
        try {
            $affiliateInput = $request->query('affiliate_id'); // could be UID or ID
            if ($affiliateInput) {
                if (is_numeric($affiliateInput)) {
                    // Input is numeric → search by ID only
                    $affiliate_id = Affiliate::where('id', (int) $affiliateInput)->value('id');
                } else {
                    // Input is string → search by UUID only
                    $affiliate_id = Affiliate::where('public_uid', $affiliateInput)->value('id');
                }
                abort_if(!$affiliate_id, 422, 'Affiliate not found');
            } else {
                // No input → fallback to current member's affiliate
                $affiliate_id = Member::where('user_id', Auth::id())->value('affiliate_id');
            }

            if (!Auth::user()->hasRole(RoleEnum::nationalRoles())) {
                $affiliate_id = Member::where('user_id', Auth::id())->first()->affiliate_id;
            }

            if (!$affiliate_id) throw new \Exception('No Affiliate Found');

            // UPDATED: Allow members with existing positions to appear in the list
            $members = Member::query()
                ->leftJoin('affiliate_officers as ao', function ($join) use ($affiliate_id) {
                    $join->on('members.id', '=', 'ao.member_id')
                        ->where('ao.affiliate_id', $affiliate_id)
                        ->where('ao.is_vacant', false)
                        ->where(function ($q) {
                            $q->whereNull('end_date')
                                ->orWhere('end_date', '>', now());
                        });
                })
                ->where('members.affiliate_id', $affiliate_id)
                ->select('members.id', 'members.first_name', 'members.last_name', 'members.affiliate_id')
                ->addSelect(\DB::raw('CASE WHEN ao.member_id IS NOT NULL THEN true ELSE false END as has_current_position'))
                ->addSelect(\DB::raw('(SELECT STRING_AGG(op.name, \', \') FROM affiliate_officers ao2 
                     JOIN officer_positions op ON ao2.position_id = op.id
                     WHERE ao2.member_id = members.id 
                     AND ao2.affiliate_id = ' . $affiliate_id . '
                     AND ao2.is_vacant = false
                     AND (ao2.end_date IS NULL OR ao2.end_date > NOW())
                     ) as current_positions'))
                ->orderBy('members.last_name')
                ->get();

            return response()->json(['data' => $members]);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'something went wrong',
                'error' => $th->getMessage()
            ], 500);
        }
    }


    public function openPosition(Request $request)
    {
        $position_id = $request->input('position');
        if (!$position_id) return response()->json(['error' => 'No Position Found'], 404);

        try {

            $positions = DB::transaction(function () use ($position_id, $request) {

                $current_officer = AffiliateOfficer::whereKey($position_id)
                    ->whereNull('end_date')
                    ->lockForUpdate()
                    ->firstOrFail();

                $affiliate_id = $current_officer->affiliate_id;
                $current_officer->update([
                    'end_date' => now()
                ]);

                $member = Member::find($current_officer->member_id);
                $user = User::find($member->user_id);

                $role = Role::where('name', RoleEnum::AFFILIATE_OFFICER->value)->first();
                $stillOfficer = AffiliateOfficer::where('member_id', $member->id)
                    ->whereNull('end_date')
                    ->exists();

                if (! $stillOfficer) {
                    $user->roles()->detach($role->id);
                }

                // Audit Log
                $role = OfficerPosition::find($current_officer->position_id);

                $new_values = [
                    $user->name => $role->name,
                ];

                $old_values = [
                    $user->name => 'removed'
                ];
                $primaryLabel = $current_officer->is_primary ? 'Primary' : 'Secondary';
                ActivityLog::create([
                    'user_id'        => Auth::id(),
                    'affiliate_id'   => $member->affiliate_id,
                    'action'         => 'removed',
                    'auditable_type' => OfficerPosition::class,
                    'auditable_id'   => $role->id,
                    'auditable_name' => "{$member->affiliate->name} - {$role->name} ($primaryLabel)",
                    'old_values'     => $old_values,
                    'new_values'     => $new_values,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);

                $positions = OfficerPosition::with([
                    'currentOfficer' => function ($q) use ($affiliate_id) {
                        $q->where('affiliate_id', $affiliate_id)
                            ->select('id', 'affiliate_id', 'position_id', 'member_id', 'start_date', 'end_date', 'is_vacant')
                            ->with([
                                'member:id,first_name,last_name',
                            ]);
                    },
                ])
                    ->orderBy('display_order')
                    ->paginate(10);

                return $positions;
            });



            return response()->json($positions);
        } catch (\Throwable $th) {

            Log::error('Error Removing Position', [$th]);

            return response()->json([
                'message' => 'something went wrong'
            ], 500);
        }
    }

    public function assignOfficer(Request $request)
    {
        $validated = $request->validate([
            'position_id' => 'required|exists:officer_positions,id',
            'member_id' => 'required|exists:members,id',
            'start_date' => 'nullable|date',
            'type' => 'required|string'
        ]);

        $user = Auth::user();
        $affiliate_id = null;

        // Determine affiliate_id based on user role
        if ($user->hasRole(RoleEnum::nationalRoles())) {
            $member = Member::find($validated['member_id']);
            $affiliate_id = $member?->affiliate_id;
        } elseif ($user->hasRole(RoleEnum::AFFILIATE_OFFICER)) {
            $affiliateMember = Member::where('user_id', $user->id)->first();
            $affiliate_id = $affiliateMember?->affiliate_id;
        }

        // Safety check — in case neither condition matched or affiliate_id not found
        if (!$affiliate_id) {
            return response()->json(['error' => 'Unable to determine affiliate.'], 422);
        }

        $validated['affiliate_id'] = $affiliate_id;
        $validated['start_date'] = $validated['start_date'] ?? now()->toDateString();
        $validated['is_primary'] = $validated['type'] === 'primary';
        $position_id = $validated['position_id'];
        $perPage = $request->input('per_page', 10);
        unset($validated['type']);

        try {

            $positions = DB::transaction(function () use ($validated, $position_id, $perPage, $affiliate_id, $request) {

                $old_officer = AffiliateOfficer::where('affiliate_id', $affiliate_id)
                    ->where('is_primary', $validated['is_primary'])
                    ->where('position_id', $position_id)
                    ->whereNull('end_date')
                    ->first();

                if ($old_officer) {
                    // Update end date
                    $old_officer->update(['end_date' => now()]);

                    // Remove old officer's role
                    $member = Member::find($old_officer->member_id);
                    $user = User::find($member->user_id);

                    $role = Role::where('name', RoleEnum::AFFILIATE_OFFICER->value)->first();

                    $stillOfficer = AffiliateOfficer::where('member_id', $member->id)
                        ->whereNull('end_date')
                        ->exists();

                    if (! $stillOfficer) {
                        $user->roles()->detach($role->id);
                    }

                    // Audit Log
                    $role = OfficerPosition::find($position_id);

                    $new_values = [
                        $user->name => $role->name,
                    ];

                    $old_values = [
                        $user->name => 'removed'
                    ];
                    $primaryLabel = $validated['is_primary'] ? 'Primary' : 'Secondary';
                    ActivityLog::create([
                        'user_id'        => Auth::id(),
                        'affiliate_id'   => $member->affiliate_id,
                        'action'         => 'removed',
                        'auditable_type' => OfficerPosition::class,
                        'auditable_id'   => $role->id,
                        'auditable_name' => "{$member->affiliate->name} - {$role->name} ({$primaryLabel})",
                        'old_values'     => $old_values,
                        'new_values'     => $new_values,
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                    ]);
                }

                // Create new assignment
                $assigned_officer = AffiliateOfficer::create($validated);

                $member = Member::find($validated['member_id']);
                $user = User::find($member->user_id);

                $role = Role::where('name', RoleEnum::AFFILIATE_OFFICER->value)->first();
                $user->roles()->syncWithoutDetaching($role->id);

                // Fetch updated positions with officers for response
                $positions = OfficerPosition::with([
                    'currentOfficer' => function ($q) use ($affiliate_id) {
                        $q->where('affiliate_id', $affiliate_id)
                            ->select('id', 'affiliate_id', 'position_id', 'member_id', 'start_date', 'end_date', 'is_vacant')
                            ->with(['member:id,first_name,last_name']);
                    },
                ])
                    ->orderBy('display_order')
                    ->paginate($perPage);


                // Audit Log
                $role = OfficerPosition::find($position_id);

                $new_values = [
                    $user->name => $role->name,
                ];

                $old_values = [
                    $user->name => 'assigned'
                ];
                $primaryLabel = $validated['is_primary'] ? 'Primary' : 'Secondary';
                ActivityLog::create([
                    'user_id'        => Auth::id(),
                    'affiliate_id'   => $member->affiliate_id,
                    'action'         => 'assigned',
                    'auditable_type' => OfficerPosition::class,
                    'auditable_id'   => $role->id,
                    'auditable_name' => "{$member->affiliate->name} - {$role->name} ({$primaryLabel})",
                    'old_values'     => $old_values,
                    'new_values'     => $new_values,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);

                return $positions;
            });

            return response()->json($positions);
        } catch (\Throwable $th) {

            Log::error('Error Assigning', [$th]);

            return response()->json([
                'message' => 'Something went wrong',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function userSearch(Request $request)
    {
        $search = $request->query('search', '');
        $uid = $request->query('uid');
        $id = $request->query('id');

        $affiliate_id = Affiliate::query()
            ->when($uid, fn($q) => $q->where('public_uid', $uid))
            ->when(!$uid && $id, fn($q) => $q->where('id', $id))
            ->value('id');

        if (!$affiliate_id) {
            return response()->json([
                'success' => false,
                'data' => []
            ]);
        }

        // UPDATED: Remove the whereDoesntHave('currentPosition') filter
        $results = Member::query()
            ->search($search)
            ->where('affiliate_id', $affiliate_id)
            // REMOVED: ->whereDoesntHave('currentPosition') // This was filtering out members with existing positions
            ->select('id', 'first_name', 'last_name', 'affiliate_id', 'member_id')
            ->addSelect(\DB::raw('(SELECT COUNT(*) FROM affiliate_officers 
                             WHERE member_id = members.id 
                             AND affiliate_id = ' . $affiliate_id . '
                             AND is_vacant = false
                             AND (end_date IS NULL OR end_date > NOW())) as current_position_count'))
            ->orderBy('last_name')
            ->limit(8)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $results,
        ]);
    }
}
