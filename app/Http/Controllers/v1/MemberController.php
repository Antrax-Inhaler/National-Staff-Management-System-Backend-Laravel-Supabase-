<?php

namespace App\Http\Controllers\v1;

use App\Enums\RoleEnum;
use App\Http\Controllers\Controller;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\MemberResource;
use App\Models\ActivityLog;
use App\Models\Affiliate;
use App\Models\Domain;
use App\Models\Member;
use App\Models\AffiliateOfficer;
use App\Models\Role;
use App\Models\User;
use App\Services\MemberIdGeneratorService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Schema;

use Illuminate\Support\Facades\Storage;

class MemberController extends Controller
{
    public function index(Request $request)
    {
        try {
            $user = Auth::user();
            $member = Member::where('user_id', $user->id)->first();
            $search = $request->query('search');

            $query = Member::query()->with([
                'currentPositions.position:id,name',
                'affiliate',
            ]);

            if (!$user->hasRole(RoleEnum::nationalRoles())) {
                if (!$member?->affiliate_id) {
                    abort(403, 'No assigned affiliate');
                }
                $query->where('affiliate_id', $member->affiliate_id);
            } else {
                $query->affiliateFilter($request);
            }

            if ($search) {
                $query->search($search);
            }

            $query->baseFilter($request);


            $sort_by = $request->query('sort_by', 'last_name');
            $sort_order = $request->query('sort_order', 'asc');
            $perPage = $request->query('per_page', 20);

            return ApiCollection::makeFromQuery($query, $perPage, $sort_by, $sort_order)
                ->useResource(MemberResource::class);
        } catch (\Throwable $th) {
            Log::error('FETCHING MEMBERS', [
                'message' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong, please try again.',
            ], 500);
        }
    }

    public function affiliate(Request $request, $uid)
    {
        try {
            $user = Auth::user();
            $member = Member::where('user_id', $user->id)->first();
            $search = $request->query('search');

            $query = Member::query()->with([
                'currentPositions.position:id,name',
                'affiliate',
            ]);

            if (!$user->hasRole(RoleEnum::nationalRoles())) {
                if (!$member?->affiliate_id) {
                    abort(403, 'No assigned affiliate');
                }
                $query->where('affiliate_id', $member->affiliate_id);
            } else {
                $query->whereHas('affiliate', function ($q) use ($uid) {
                    $q->where('public_uid', $uid);
                });
            }

            if ($search) {
                $query->search($search);
            }

            $query->baseFilter($request);


            $sort_by = $request->query('sort_by', 'last_name');
            $sort_order = $request->query('sort_order', 'asc');
            $perPage = $request->query('per_page', 20);

            return ApiCollection::makeFromQuery($query, $perPage, $sort_by, $sort_order)
                ->useResource(MemberResource::class);
        } catch (\Throwable $th) {
            Log::error('FETCHING AFFILIATE MEMBERS', [
                'message' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong, please try again.',
            ], 500);
        }
    }

    public function export(Request $request)
    {
        try {
            $user = Auth::user();
            $member = Member::where('user_id', $user->id)->first();
            $affiliate_uid = $request->query('affiliate_uid');
            $ids = $request->query('ids');
            $search = $request->query('search');

            $member_ids = is_array($ids) ? $ids : explode(',', $ids);

            $query = Member::query()->with([
                'currentPositions.position:id,name',
                'affiliate',
            ]);

            if (!$user->hasRole(RoleEnum::nationalRoles())) {
                if (!$member?->affiliate_id) {
                    abort(403, 'No assigned affiliate');
                }
                $query->where('affiliate_id', $member->affiliate_id);
            } else if ($affiliate_uid) {
                $query->whereHas('affiliate', function ($q) use ($affiliate_uid) {
                    $q->where('public_uid', $affiliate_uid);
                });
            } else {
                $query->affiliateFilter($request);
            }

            if ($search) {
                $query->search($search);
            }

            $query->baseFilter($request);

            if ($ids) {
                $query->whereIn('id', $member_ids);
            }

            $sort_by = $request->query('sort_by', 'name');
            $sort_order = $request->query('sort_order', 'asc');
            $per_page = 'All';


            return ApiCollection::makeFromQuery($query, $per_page, $sort_by, $sort_order)
                ->useResource(MemberResource::class);
        } catch (\Throwable $th) {
            Log::error('FETCHING EXPORT MEMBERS', [
                'message' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong, please try again.',
            ], 500);
        }
    }


    public function remove(Request $request)
    {

        $validated = $request->validate([
            'ids'   => ['required', 'array'],
            'ids.*' => ['exists:members,id'],
            'force' => ['required', 'boolean'],
        ]);

        try {
            $members = Member::withTrashed()
                ->whereIn('id', $validated['ids'])
                ->whereHas('user', function ($q) {
                    $q->withTrashed()->where('id', '!=', Auth::id());
                })
                ->with([
                    'currentPositions',
                    'user' => fn($q) => $q->withTrashed(),
                ])
                ->get();

            // Prevent deletion if assigned to positions
            if ($members->contains(fn($m) => $m->currentPositions->isNotEmpty())) {
                return response()->json([
                    'success' => false,
                    'message' => 'Member cannot be removed while assigned to a position.',
                ], 400);
            }

            DB::transaction(function () use ($members, $validated) {
                foreach ($members as $member) {
                    if ($validated['force']) {
                        $member->forceDelete();
                        $member->user?->forceDelete();
                    } else {
                        if (!$member->trashed()) {
                            $member->delete();
                        }

                        if ($member->user && !$member->user->trashed()) {
                            $member->user->delete();
                        }
                    }
                }
            });

            return response()->json([
                'success' => true,
                'message' => 'Member successfully removed',
            ], 200);
        } catch (\Throwable $th) {

            Log::error('REMOVE MEMBER', [
                'message' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong, please try again.',
            ], 500);
        }
    }

    public function restore(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:members,id',
        ]);

        try {
            $members = Member::withTrashed()
                ->whereIn('id', $validated['ids'])
                ->with(['currentPositions', 'user' => fn($q) => $q->withTrashed()])
                ->get();

            DB::transaction(function () use ($members) {
                foreach ($members as $member) {
                    $member->restore();
                    $member->user?->restore();
                }
            });

            return response()->json([
                'success' => true,
                'message' => 'Member successfully restored',
            ], 200);
        } catch (\Throwable $th) {

            Log::error('RESTORE MEMBER', [
                'message' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong, please try again.',
            ], 500);
        }
    }

    public function archive(Request $request)
    {
        try {
            $user = Auth::user();
            $member = Member::where('user_id', $user->id)->first();
            $affiliate_uid = $request->query('affiliate_uid');
            $search = $request->query('search');

            $query = Member::query()->onlyTrashed()->with([
                'currentPositions.position:id,name',
                'affiliate',
            ]);

            if (!$user->hasRole(RoleEnum::nationalRoles())) {
                if (!$member?->affiliate_id) {
                    abort(403, 'No assigned affiliate');
                }
                $query->where('affiliate_id', $member->affiliate_id);
            } else if ($affiliate_uid) {
                $query->whereHas('affiliate', function ($q) use ($affiliate_uid) {
                    $q->where('public_uid', $affiliate_uid);
                });
            } else {
                $query->affiliateFilter($request);
            }

            if ($search) {
                $query->search($search);
            }

            $query->baseFilter($request);

            $sort_by = $request->query('sort_by', 'last_name');
            $sort_order = $request->query('sort_order', 'asc');
            $perPage = $request->query('per_page', 20);

            return ApiCollection::makeFromQuery($query, $perPage, $sort_by, $sort_order)
                ->useResource(MemberResource::class);
        } catch (\Throwable $th) {
            Log::error('FETCHING ARCHIVED MEMBERS', [
                'message' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong, please try again.',
            ], 500);
        }
    }


    public function create(Request $request)
    {
        // TEMPORARILY MADE date_of_birth, date_of_hire, gender, self_id NON-REQUIRED
        $validated = $request->validate([
            'first_name' => ['required', 'string'],
            'last_name' => ['required', 'string'],
            'level' => ['required', 'string'],
            'employment_status' => ['required', 'string'],
            'address_line1' => ['required', 'string'],
            'address_line2' => ['nullable', 'string'],
            'city' => ['required', 'string'],
            'state' => ['required', 'string'],
            'zip_code' => ['required', 'string', 'max:10'],
            'home_email' => [
                'required',
                'string',
                'email',
                'unique:users,email',
                function ($attribute, $value, $fail) use ($request) {

                    // Determine affiliate ID from input (ID or UUID)
                    $affiliateInput = $request->input('affiliate_id');

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

                    // Extract domain and TLD
                    $domain = substr(strrchr($value, '@'), 1); // e.g., example.org
                    $tld = '.' . substr(strrchr($domain, '.'), 1); // e.g., ".org"
                    $domainToCheck = [$domain, $tld];

                    // Get blacklisted domains/TLDs for this affiliate
                    $blacklisted = Domain::where('is_blacklisted', true)
                        ->where('affiliate_id', $affiliate_id)
                        ->pluck('domain')
                        ->toArray();

                    // Fail if domain or TLD is blacklisted
                    if (array_intersect($domainToCheck, $blacklisted)) {
                        $fail("The email domain \"$domain\" is blacklisted.");
                        return;
                    }

                    // Only enforce allowed domains for .org emails
                    if ($tld === '.org') {
                        $allowed = Domain::where('is_blacklisted', false)
                            ->where('affiliate_id', $affiliate_id)
                            ->pluck('domain')
                            ->toArray();

                        if (!empty($allowed) && !array_intersect($domainToCheck, $allowed)) {
                            $fail("The .org email domain \"$domain\" is not allowed for this affiliate.");
                        }
                    }

                    // Non-.org emails are automatically allowed (unless blacklisted)
                },
            ],
            'mobile_phone' => ['required', 'string', 'max:15'],
            'home_phone' => ['nullable', 'string'],
            'date_of_birth' => ['nullable', 'date'], // TEMPORARILY NON-REQUIRED
            'date_of_hire' => ['nullable', 'date'], // TEMPORARILY NON-REQUIRED
            'gender' => ['nullable', 'string', 'in:male,female,non-binary,none_of_these_choices,prefer_not_to_disclose'], // UPDATED OPTIONS, NON-REQUIRED
            'self_id' => ['nullable', 'string'], // TEMPORARILY NON-REQUIRED, CHANGED LABEL TO ETHNICITY
            'member_id' => ['nullable', 'string'],
            'status' => ['required', 'string'],
            'affiliate_id' => ['nullable'],
            'profile_photo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:5120']
        ]);

        try {
            $affiliateInput = $request->input('affiliate_id'); // could be UID or ID

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

            $validated['affiliate_id'] = $affiliate_id;

            $validated['created_by'] = Auth::id();

            $member = DB::transaction(function () use ($validated, $request) {
                $new_user = User::updateOrCreate(
                    [
                        'email' => $validated['home_email'],
                    ],
                    [
                        'name' => $validated['first_name'] . ' ' . $validated['last_name'],
                        'email_verified_at' => Carbon::now(),
                        'password' => Hash::make(uniqid()),
                    ]
                );

                $validated['user_id'] = $new_user->id;

                if ($request->hasFile('profile_photo')) {
                    $file = $request->file('profile_photo');
                    $extension = $file->getClientOriginalExtension();
                    $fileName = 'profile_' . time() . '_' . uniqid() . '.' . $extension;
                    $filePath = 'profile-photos/' . $fileName;

                    Storage::disk('supabase')->put($filePath, file_get_contents($file));
                    $validated['photo_path'] = $filePath;
                    $max_expires_at = now()->addWeek();
                    $validated['photo_signed_url'] = Storage::disk('supabase')->temporaryUrl($filePath, $max_expires_at);
                    $validated['photo_signed_expires_at'] = $max_expires_at->subDay();
                }

                $new_member = Member::create($validated);

                if (empty($validated['member_id']) && empty($new_member->member_id)) {
                    $memberId = app(MemberIdGeneratorService::class)->generate(
                        affiliate: $validated['affiliate_id'],
                        memberId: $new_member->id
                    );

                    $new_member->update([
                        'member_id' => $memberId
                    ]);
                }

                $role_id = Role::where('name', RoleEnum::AFFILIATE_MEMBER)->first()->id;
                $new_user->roles()->attach($role_id);

                return $new_member->load(['user', 'affiliate']);
            });

            return response()->json([
                'success' => true,
                'data'    => $member,
                'message' => 'Member created successfully'
            ], 201);
        } catch (\Throwable $th) {

            Log::error('CREATING MEMBER', [
                'message' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong, please try again.',
            ], 500);
        }
    }

    public function update(Request $request)
    {
        // TEMPORARILY MADE date_of_birth, date_of_hire, gender, self_id NON-REQUIRED
        $validated = $request->validate([
            'id' => ['required', 'exists:members,id'],
            'first_name' => ['required', 'string'],
            'last_name' => ['required', 'string'],
            'level' => ['required', 'string'],
            'employment_status' => ['required', 'string'],
            'address_line1' => ['required', 'string'],
            'address_line2' => ['nullable', 'string'],
            'city' => ['required', 'string'],
            'state_id' => ['required', 'integer'],
            'state' => ['required', 'string'],
            'zip_code' => ['required', 'string'],
            'home_email' => [
                'required',
                'string',
                'email',
                Rule::unique('users', 'email')->ignore($request->input('user_id')),
                function ($attribute, $value, $fail) use ($request) {
                    // Determine affiliate ID (from request or logged-in user's member record)
                    $affiliate_id = $request->affiliate_id
                        ?? Member::where('user_id', Auth::id())->value('affiliate_id');

                    // Extract domain and TLD
                    $domain = substr(strrchr($value, '@'), 1); // e.g., example.org
                    $tld = '.' . substr(strrchr($domain, '.'), 1); // e.g., ".org"
                    $domainToCheck = [$domain, $tld];

                    // Get blacklisted domains/TLDs for this affiliate
                    $blacklisted = Domain::where('is_blacklisted', true)
                        ->where('affiliate_id', $affiliate_id)
                        ->pluck('domain')
                        ->toArray();

                    // Fail immediately if the domain is blacklisted
                    if (array_intersect($domainToCheck, $blacklisted)) {
                        $fail("The email domain \"$domain\" is blacklisted.");
                        return;
                    }

                    // Only enforce allowed domains for .org emails
                    if ($tld === '.org') {
                        $allowed = Domain::where('is_blacklisted', false)
                            ->where('affiliate_id', $affiliate_id)
                            ->pluck('domain')
                            ->toArray();

                        if (!empty($allowed) && !array_intersect($domainToCheck, $allowed)) {
                            $fail("The .org email domain \"$domain\" is not allowed for this affiliate.");
                        }
                    }

                    // Non-.org emails are automatically allowed (unless blacklisted)
                },
            ],
            'mobile_phone' => ['required', 'string'],
            'home_phone' => ['nullable', 'string'],
            'date_of_birth' => ['nullable', 'date'], // TEMPORARILY NON-REQUIRED
            'date_of_hire' => ['nullable', 'date'], // TEMPORARILY NON-REQUIRED
            'gender' => ['nullable', 'string', 'in:male,female,non-binary,none_of_these_choices,prefer_not_to_disclose'], // UPDATED OPTIONS, NON-REQUIRED
            'self_id' => ['nullable', 'string'], // TEMPORARILY NON-REQUIRED, CHANGED LABEL TO ETHNICITY
            'member_id' => ['nullable', 'string'],
            'status' => ['required', 'string'],
            'profile_photo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:5120']
        ]);

        try {
            $validated['updated_by'] = Auth::id();
            if ($request->input('affiliate_id')) {
                $validated['affiliate_id'] = $request->input('affiliate_id');
            }

            $updatedMember = DB::transaction(function () use ($validated, $request) {
                $update_member = Member::find($validated['id']);
                $user = User::find($update_member->user_id);

                // Check if email is being changed
                $isEmailChanged = $user->email !== $validated['home_email'];
                $oldEmail = $user->email;
                $newEmail = $validated['home_email'];

                // Update user details
                $user->update([
                    'name' => $validated['first_name'] . ' ' . $validated['last_name'],
                    'email' => $newEmail,
                ]);

                if ($request->hasFile('profile_photo')) {
                    $file = $request->file('profile_photo');

                    if ($update_member->photo_path) {
                        try {
                            Storage::disk('supabase')->delete($update_member->photo_path);
                        } catch (\Exception $e) {
                            Log::warning('Failed to delete old profile photo: ' . $e->getMessage());
                        }
                    }

                    $extension = $file->getClientOriginalExtension();
                    $fileName = 'profile_' . time() . '_' . uniqid() . '.' . $extension;
                    $filePath = 'profile-photos/' . $fileName;
                    Storage::disk('supabase')->put($filePath, file_get_contents($file));
                    $validated['photo_path'] = $filePath;
                    $max_expires_at = now()->addWeek();
                    $validated['photo_signed_url'] = Storage::disk('supabase')->temporaryUrl($filePath, $max_expires_at);
                    $validated['photo_signed_expires_at'] = $max_expires_at->subDay();
                }

                $update_member->update($validated);

                return $update_member->load(['user', 'affiliate']);
            });

            return response()->json([
                'success' => true,
                'data'    => $updatedMember,
                'message' => 'Member updated successfully'
            ], 200);
        } catch (\Throwable $th) {
            Log::error('UPDATING MEMBER', [
                'message' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong, please try again.',
            ], 500);
        }
    }

    public function generateId()
    {
        try {
            $user = Auth::user();
            $member = $user->member;

            if (! $member) {
                return response()->json([
                    'success' => false,
                    'message' => 'User has no member record',
                ], 404);
            }

            if ($member->member_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Member already has a Member ID',
                ], 409);
            }

            $memberId = app(MemberIdGeneratorService::class)->generate(
                affiliate: $member->affiliate_id,
                memberId: $member->id
            );

            $member->update([
                'member_id' => $memberId,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Successfully generated Member ID',
                'member_id' => $memberId,
            ]);
        } catch (\Throwable $e) {
            Log::error('Member ID generation failed', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate Member ID',
            ], 500);
        }
    }




    // =================================================================================================================================================

    /**
     * Check if member is a Organization Officer
     */
    private function isOrganizationOfficer(Member $member): bool
    {
        $nationalRoles = ['Organization Administrator', 'ORG Executive Committee', 'ORG Research Committee'];

        return \App\Models\MemberOrganizationRole::where('member_id', $member->id)
            ->whereHas('role', function ($query) use ($nationalRoles) {
                $query->whereIn('name', $nationalRoles);
            })
            ->exists(); // Removed the end_date check since column doesn't exist
    }
    /**
     * Check if member has one of the allowed affiliate officer positions
     */
    private function hasAllowedOfficerPosition(Member $member): bool
    {
        // Map of allowed position names and their possible variations
        $allowedPositionPatterns = [
            'president' => ['president', 'pres', 'chair', 'chairperson'],
            'secretary' => ['secretary', 'sec'],
            'treasurer' => ['treasurer', 'treas'],
            'membership' => ['membership', 'membership chair', 'membership coordinator']
        ];

        $currentOfficerPositions = \App\Models\AffiliateOfficer::where('member_id', $member->id)
            ->where('affiliate_id', $member->affiliate_id)
            ->where('is_vacant', false)
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', now());
            })
            ->with('position')
            ->get()
            ->pluck('position.name')
            ->map(fn($name) => strtolower($name));

        // Check if any current position matches allowed patterns
        foreach ($currentOfficerPositions as $position) {
            foreach ($allowedPositionPatterns as $allowedPatterns) {
                foreach ($allowedPatterns as $pattern) {
                    if (str_contains($position, $pattern)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public function removeUser(Request $request, $id)
    {
        $force = $request->boolean('force', false);

        try {
            $user = User::with('member.currentPosition')->withTrashed()->findOrFail($id);

            if ($user->member && $user->member->currentPosition) {
                return response()->json([
                    'success' => false,
                    'message' => "Member cannot be removed while assigned to a position.",
                ], 400);
            }

            DB::transaction(function () use ($user, $force) {
                if ($force) {
                    $user->forceDelete();
                } else {
                    $user->delete();
                }
            });

            return response()->json([
                'success' => true,
                'message' => $force ? 'Member deleted successfully.' : 'Member archived successfully.',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'User not found.',
            ], 404);
        } catch (\Throwable $th) {
            Log::error('Member remove failed', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to remove member.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function restoreUser($id)
    {
        try {
            $user = User::withTrashed()->findOrFail($id);

            if (! $user->trashed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not archived.',
                ], 400);
            }

            DB::transaction(function () use ($user) {
                $user->restore();
            });

            return response()->json([
                'success' => true,
                'message' => 'User restored successfully.',
            ], 200);
        } catch (\Throwable $th) {
            Log::error('User restore failed', [
                'error' => $th->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to restore user.',
            ], 500);
        }
    }


    public function getMemberPhoto($id)
    {
        try {
            $member = Member::findOrFail($id);

            if (!$member->profile_photo_url) {
                return response()->json([
                    'success' => false,
                    'message' => 'No profile photo found'
                ], 404);
            }

            // Generate signed URL for the photo
            $signedUrl = Storage::disk('supabase')->temporaryUrl(
                $member->profile_photo_url,
                now()->addMinutes(10)
            );

            // Fetch the image
            $imageContent = file_get_contents($signedUrl);

            return response($imageContent)
                ->header('Content-Type', 'image/jpeg') // Adjust based on actual image type
                ->header('Cache-Control', 'public, max-age=3600');
        } catch (\Exception $e) {
            Log::error('Member photo fetch error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch member photo'
            ], 500);
        }
    }
    public function show($uid)
    {
        try {
            // Get the member being requested - ONLY EXISTING COLUMNS
            $member = Member::select([
                'id',
                'user_id',
                'affiliate_id',
                'member_id',
                'first_name',
                'last_name',
                'level',
                'employment_status',
                'status',
                'address_line1',
                'address_line2',
                'city',
                'state',
                'zip_code',
                'work_email',
                'work_phone',
                'home_email',
                'home_phone',
                'self_id',
                'non_nso',
                'created_by',
                'updated_by',
                'state_id',
                'mobile_phone',
                'date_of_birth',
                'date_of_hire',
                'gender',
                'photo_signed_url',
                'photo_signed_expires_at',
                'photo_path',
                'created_at',
                'updated_at'
            ])
                ->with([
                    'affiliate:id,name,logo_url,affiliate_type',
                    'user:id,email,supabase_uid,email_verified_at',
                    'creator:id,name,email',
                    'updater:id,name,email'
                ])
                ->firstWhere('public_uid', $uid);

            if (!$member) {
                return response()->json([
                    'success' => false,
                    'message' => 'Member not found'
                ], 404);
            }

            // Format the member data with only existing fields
            $memberData = $this->formatMemberDataOptimized($member);

            return response()->json([
                'success' => true,
                'data' => $memberData,
                'message' => 'Member details retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get member details: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'member_id' => ''
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve member details',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Optimized member data formatting - ONLY EXISTING FIELDS
     */
    private function formatMemberDataOptimized(Member $member): array
    {
        $photo_signed_url = null;

        if ($member->photo_path) {
            $needsRefresh = !$member->photo_signed_expires_at ||
                Carbon::parse($member->photo_signed_expires_at)->lte(now());

            if ($needsRefresh) {
                $expiresAt = now()->addHours(5);

                $photo_signed_url = Storage::disk('supabase')
                    ->temporaryUrl($member->photo_path, $expiresAt);
            } else {
                $photo_signed_url = $member->photo_signed_url;
            }
        }

        $officialEmail = $this->generateNameBasedEmail($member->first_name, $member->last_name, $member->id);

        $data = [
            'id' => $member->id,
            'member_id' => $member->member_id,
            'first_name' => $member->first_name,
            'last_name' => $member->last_name,
            'full_name' => $member->first_name . ' ' . $member->last_name,
            'level' => $member->level,
            'status' => $member->status,
            'employment_status' => $member->employment_status,
            'gender' => $member->gender,
            'self_id' => $member->self_id,
            'non_nso' => $member->non_nso,

            'contact_info' => [
                'home_email' => $member->home_email,
                'work_email' => $member->work_email,
                'official_email' => $officialEmail, // Add this line
                'mobile_phone' => $member->mobile_phone,
                'work_phone' => $member->work_phone,
                'home_phone' => $member->home_phone,
            ],

            'address' => [
                'address_line1' => $member->address_line1,
                'address_line2' => $member->address_line2,
                'city' => $member->city,
                'state' => $member->state,
                'state_id' => $member->state_id,
                'zip_code' => $member->zip_code,
            ],

            // Dates - ONLY EXISTING FIELDS
            'dates' => [
                'date_of_birth' => $member->date_of_birth,
                'date_of_hire' => $member->date_of_hire,
                'created_at' => $member->created_at,
                'updated_at' => $member->updated_at,
            ],

            // Profile Information - ONLY EXISTING FIELDS
            'profile' => [
                'photo_signed_url' => $photo_signed_url
            ],
        ];


        // Add affiliate information if exists
        if ($member->affiliate) {
            $data['affiliate'] = [
                'id' => $member->affiliate->id,
                'name' => $member->affiliate->name,
                'affiliate_type' => $member->affiliate->affiliate_type,
                'logo_url' => $member->affiliate->logo_url
                    ? $this->generateTemporaryUrl($member->affiliate->logo_url)
                    : null,
            ];
        }

        // Add user account information if exists
        if ($member->user) {
            $data['user_account'] = [
                'id' => $member->user->id,
                'email' => $member->user->email,
                'supabase_uid' => $member->user->supabase_uid,
                'email_verified_at' => $member->user->email_verified_at,
            ];
        }

        // Load officer positions separately to avoid N+1 queries
        $data['officer_positions'] = $this->getMemberOfficerPositions($member->id);

        // Load national roles separately
        $data['national_roles'] = $this->getMemberOrganizationRoles($member->id);

        // Add metadata
        if ($member->creator) {
            $data['metadata']['created_by'] = [
                'id' => $member->creator->id,
                'name' => $member->creator->name,
                'email' => $member->creator->email,
            ];
        }

        if ($member->updater) {
            $data['metadata']['updated_by'] = [
                'id' => $member->updater->id,
                'name' => $member->updater->name,
                'email' => $member->updater->email,
            ];
        }

        // Add statistics
        $data['statistics'] = [
            'age' => $member->date_of_birth ? Carbon::parse($member->date_of_birth)->age : null,
            'tenure_years' => $member->date_of_hire ? Carbon::parse($member->date_of_hire)->diffInYears(now()) : null,
            'is_active' => $member->status === 'Active',
        ];

        return $data;
    }
    private function generateNameBasedEmail($firstName, $lastName, $memberId)
    {
        if (!$firstName || !$lastName) {
            return 'member@organizationstaff.org';
        }

        $hasOrganizationLeadershipRole = false;

        try {
            $member = Member::find($memberId);
            if ($member && $member->user) {
                // Load roles if not already loaded
                $member->load(['user.roles']);

                // Define national leadership roles
                $nationalLeadershipRoles = [
                    'president',
                    'vice president defense',
                    'vice president program',
                    'secretary',
                    'treasurer',
                    'director'
                ];

                foreach ($nationalLeadershipRoles as $rolePattern) {
                    foreach ($member->user->roles as $role) {
                        $roleName = strtolower($role->name);
                        if (strpos($roleName, $rolePattern) !== false) {
                            $hasOrganizationLeadershipRole = true;
                            break 2;
                        }
                    }
                }

                // Check for Region X Directors
                for ($i = 1; $i <= 7; $i++) {
                    if (
                        in_array("region {$i} director", array_map('strtolower', $member->user->roles->pluck('name')->toArray())) ||
                        in_array("region_{$i}_director", array_map('strtolower', $member->user->roles->pluck('name')->toArray()))
                    ) {
                        $hasOrganizationLeadershipRole = true;
                        break;
                    }
                }

                // Check for At Large X Directors
                if (
                    in_array('at large director associate', array_map('strtolower', $member->user->roles->pluck('name')->toArray())) ||
                    in_array('at_large_director_associate', array_map('strtolower', $member->user->roles->pluck('name')->toArray())) ||
                    in_array('at large director professional', array_map('strtolower', $member->user->roles->pluck('name')->toArray())) ||
                    in_array('at_large_director_professional', array_map('strtolower', $member->user->roles->pluck('name')->toArray()))
                ) {
                    $hasOrganizationLeadershipRole = true;
                }
            }
        } catch (\Exception $e) {
            // If there's an error checking roles, default to not generating the email
            return null;
        }

        // Only generate official email for national leadership roles
        if (!$hasOrganizationLeadershipRole) {
            return null;
        }

        $cleanFirstName = strtolower(preg_replace('/[^a-zA-Z]/', '', $firstName));
        $cleanLastName = strtolower(preg_replace('/[^a-zA-Z]/', '', $lastName));

        if (!$cleanFirstName || !$cleanLastName) {
            return 'member@organizationstaff.org';
        }

        return $cleanFirstName . '.' . $cleanLastName . '@organizationstaff.org';
    }

    /**
     * Get member officer positions with optimized query
     */
    private function getMemberOfficerPositions($memberId): array
    {
        $officerPositions = AffiliateOfficer::where('member_id', $memberId)
            ->where('is_vacant', false)
            ->when(Schema::hasColumn('affiliate_officers', 'end_date'), function ($query) {
                $query->where(function ($q) {
                    $q->whereNull('end_date')
                        ->orWhere('end_date', '>=', now());
                });
            })
            ->with([
                'position:id,name',
                'affiliate:id,name'
            ])
            ->select([
                'id',
                'position_id',
                'affiliate_id',
                'start_date',
                'end_date',
                'is_vacant',
                'is_primary',
                'created_at',
                'updated_at'
            ])
            ->get()
            ->map(function ($officer) {
                $data = [
                    'id' => $officer->id,
                    'position_name' => $officer->position->name ?? null,
                    'affiliate_name' => $officer->affiliate->name ?? null,
                    'start_date' => $officer->start_date,
                    'is_vacant' => $officer->is_vacant,
                    'is_primary' => $officer->is_primary,
                    'created_at' => $officer->created_at,
                    'updated_at' => $officer->updated_at,
                ];

                if (isset($officer->end_date)) {
                    $data['end_date'] = $officer->end_date;
                }

                return $data;
            })
            ->toArray();

        return $officerPositions;
    }

    /**
     * Get member national roles with optimized query
     */
    private function getMemberOrganizationRoles($memberId): array
    {
        if (!class_exists('App\Models\MemberOrganizationRole')) {
            return [];
        }

        $nationalRoles = \App\Models\MemberOrganizationRole::where('member_id', $memberId)
            ->with('role:id,name')
            ->select([
                'id',
                'role_id',
                'created_at',
                'updated_at',
                'created_by'
            ])
            ->get()
            ->map(function ($role) {
                return [
                    'id' => $role->id,
                    'role_name' => $role->role->name ?? null,
                    'created_at' => $role->created_at,
                    'updated_at' => $role->updated_at,
                    'created_by' => $role->created_by,
                ];
            })
            ->toArray();

        return $nationalRoles;
    }

    /**
     * Generate temporary URL with error handling
     */
    private function generateTemporaryUrl($path, $expirationMinutes = 60)
    {
        try {
            if (!$path) {
                return null;
            }

            return Storage::disk('supabase')->temporaryUrl(
                $path,
                now()->addMinutes($expirationMinutes)
            );
        } catch (\Exception $e) {
            Log::error('Error generating temporary URL: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if user can view member details - OPTIMIZED
     */
    private function canViewMember(Member $currentMember, Member $memberToView): bool
    {
        // Users can always view their own details
        if ($currentMember->id === $memberToView->id) {
            return true;
        }

        // Check if current member is a national officer
        if ($this->isOrganizationOfficer($currentMember)) {
            return true;
        }

        // Check if current user is in the same affiliate as the member being viewed
        if ($currentMember->affiliate_id !== $memberToView->affiliate_id) {
            return false;
        }

        // Check if current user has any officer position in the same affiliate
        return $this->hasAnyOfficerPositionInAffiliate($currentMember->id, $currentMember->affiliate_id);
    }

    /**
     * Check if member has any officer position in specific affiliate - OPTIMIZED
     */
    private function hasAnyOfficerPositionInAffiliate($memberId, $affiliateId): bool
    {
        $query = AffiliateOfficer::where('member_id', $memberId)
            ->where('affiliate_id', $affiliateId)
            ->where('is_vacant', false);

        if (Schema::hasColumn('affiliate_officers', 'end_date')) {
            $query->where(function ($q) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>=', now());
            });
        }

        return $query->exists();
    }

    public function archives(Request $request)
    {
        $user = Auth::user();
        $member = $user->member;

        // Get affiliate query param (null if not passed)
        $affiliate = $request->query('affiliate', null);

        // Non-national users: fallback to member's affiliate if not provided
        if (! $user->hasRole(RoleEnum::nationalRoles())) {
            if (! $affiliate) {
                $affiliate = $member?->affiliate_id;
            }

            if (! $affiliate) {
                abort(403, 'No assigned affiliate');
            }
        } else {
            $affiliate = Affiliate::where('public_uid', $affiliate)->value('id');
        }

        // Base query
        $query = Member::with([
            'currentPosition.position:id,name',
            'affiliate',
        ])->onlyTrashed();

        // Apply affiliate filter only if $affiliate exists
        if ($affiliate) {
            $query->where('affiliate_id', $affiliate);
        }

        // Text search
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'ilike', "%{$search}%")
                    ->orWhere('last_name', 'ilike', "%{$search}%")
                    ->orWhere('home_email', 'ilike', "%{$search}%")
                    ->orWhere('member_id', 'ilike', "%{$search}%")
                    ->orWhere('mobile_phone', 'ilike', "%{$search}%")
                    ->orWhere('work_email', 'ilike', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $request->query('sort_by', 'last_name');
        $sortOrder = $request->query('sort_order', 'asc');

        $sortOrder = in_array(strtolower($sortOrder), ['asc', 'desc']) ? $sortOrder : 'asc';

        $sortableColumns = [
            'first_name',
            'last_name',
            'member_id',
            'level',
            'employment_status',
            'gender',
            'date_of_birth',
            'date_of_hire',
            'status',
            'created_at',
            'updated_at',
            'city',
            'state',
        ];

        if (in_array($sortBy, $sortableColumns)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('last_name', 'asc');
        }

        $perPage = $request->query('per_page', 20);

        return ApiCollection::makeFromQuery($query, $perPage, $sortBy, $sortOrder)
            ->useResource(MemberResource::class);
    }
}
