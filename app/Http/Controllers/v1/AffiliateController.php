<?php

namespace App\Http\Controllers\v1;

use App\Enums\RoleEnum;
use App\Http\Controllers\Controller;
use App\Http\Resources\AffiliateResource;
use App\Http\Resources\ApiCollection;
use App\Models\ActivityLog;
use App\Models\Affiliate;
use App\Models\DocumentFolder;
use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AffiliateController extends Controller
{
    public function all(Request $request)
    {
        $perPage = $request->input('per_page', 10);
        $search = $request->input('search');
        $sortBy = $request->input('sort_by', 'name');
        $sortOrder = $request->input('sort_order', 'asc');

        $query = Affiliate::query()
            ->withCount([
                'members as members_count', // total count
                'members as associate_count' => function ($query) {
                    $query->where('level', 'Associate');
                },
                'members as professional_count' => function ($query) {
                    $query->where('level', 'Professional');
                }
            ]);

        // Search
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('employer_name', 'ilike', "%{$search}%")
                    ->orWhere('ein', 'ilike', "%{$search}%");
            });
        }

        // Filters (keep existing filter logic)
        if ($request->has('affiliate_type')) {
            $types = explode(',', $request->input('affiliate_type'));
            $hasNotSet = in_array('Not Set', $types);

            if ($hasNotSet) {
                // Remove "Not Set" from array
                $types = array_filter($types, fn($type) => $type !== 'Not Set');

                if (count($types) > 0) {
                    $query->where(function ($q) use ($types) {
                        $q->whereIn('affiliate_type', $types)
                            ->orWhereNull('affiliate_type');
                    });
                } else {
                    $query->whereNull('affiliate_type');
                }
            } else {
                $query->whereIn('affiliate_type', $types);
            }
        }

        if ($request->has('cbc_region')) {
            $regions = explode(',', $request->input('cbc_region'));
            $hasNotSet = in_array('Not Set', $regions);

            if ($hasNotSet) {
                $regions = array_filter($regions, fn($region) => $region !== 'Not Set');

                if (count($regions) > 0) {
                    $query->where(function ($q) use ($regions) {
                        $q->whereIn('cbc_region', $regions)
                            ->orWhereNull('cbc_region');
                    });
                } else {
                    $query->whereNull('cbc_region');
                }
            } else {
                $query->whereIn('cbc_region', $regions);
            }
        }

        if ($request->has('ORG_region')) {
            $regions = explode(',', $request->input('ORG_region'));
            $hasNotSet = in_array('Not Set', $regions);

            if ($hasNotSet) {
                $regions = array_filter($regions, fn($region) => $region !== 'Not Set');

                if (count($regions) > 0) {
                    $query->where(function ($q) use ($regions) {
                        $q->whereIn('ORG_region', $regions)
                            ->orWhereNull('ORG_region');
                    });
                } else {
                    $query->whereNull('ORG_region');
                }
            } else {
                $query->whereIn('ORG_region', $regions);
            }
        }

        if ($request->has('has_logo')) {
            if ($request->input('has_logo') === 'true') {
                $query->whereNotNull('logo_url');
            } else {
                $query->whereNull('logo_url');
            }
        }

        if ($request->has('has_ein')) {
            if ($request->input('has_ein') === 'true') {
                $query->whereNotNull('ein')->where('ein', '!=', '');
            } else {
                $query->where(function ($q) {
                    $q->whereNull('ein')->orWhere('ein', '=', '');
                });
            }
        }

        if ($request->has('has_employer')) {
            if ($request->input('has_employer') === 'true') {
                $query->whereNotNull('employer_name')->where('employer_name', '!=', '');
            } else {
                $query->where(function ($q) {
                    $q->whereNull('employer_name')->orWhere('employer_name', '=', '');
                });
            }
        }

        // Sorting
        $validSortColumns = ['name', 'members_count', 'created_at', 'updated_at', 'affiliation_date'];
        $validSortOrders = ['asc', 'desc'];

        if (in_array($sortBy, $validSortColumns) && in_array($sortOrder, $validSortOrders)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('name', 'asc');
        }

        return ApiCollection::makeFromQuery($query, $perPage)
            ->useResource(AffiliateResource::class);
    }

    public function info($uid)
    {
        try {
            $affiliate = Affiliate::firstWhere('public_uid', $uid);

            if (!$affiliate) {
                return response()->json([
                    'success' => false,
                    'message' => 'Affiliate not found.',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $affiliate,
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Something went wrong.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function create(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'state' => 'nullable|string|max:100',
            'employer_name' => 'nullable|string|max:255',
            'ein' => 'nullable|string|max:20',
            'affiliation_date' => 'nullable|date',
            'affiliate_type' => 'nullable|in:Associate,Professional,Wall-to-Wall',
            'cbc_region' => 'nullable|in:Northeast,Cooridor,South,Central,Western',
            'ORG_region' => 'nullable|string|in:1,2,3,4,5,6,7',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
        ]);

        try {
            $affiliate = DB::transaction(function () use ($validated, $request) {
                // Handle logo upload
                if ($request->hasFile('logo')) {
                    $file = $request->file('logo');
                    $extension = $file->getClientOriginalExtension();
                    $fileName = 'affiliate_logo_' . time() . '_' . Str::random(10) . '.' . $extension;
                    $filePath = 'affiliate-logos/' . $fileName;

                    Storage::disk('supabase')->put($filePath, file_get_contents($file));
                    $validated['logo_path'] = $filePath;
                    $max_expires_at = now()->addWeek();
                    $validated['logo_signed_url'] = Storage::disk('supabase')->temporaryUrl($filePath, $max_expires_at);
                    $validated['logo_signed_expires_at'] = $max_expires_at->subDay();
                }

                $affiliate = Affiliate::create($validated);

                DocumentFolder::firstOrCreate([
                    'affiliate_id' => $affiliate->id,
                    'root' => true,
                ], [
                    'folder_name' => null
                ]);

                return $affiliate;
            });

            Cache::forget('affiliate_employers_options');
            Cache::forget('affiliate_employers_options_' . $validated['affiliate_id']);

            return response()->json([
                'success' => true,
                'data'    => $affiliate,
                'message' => 'affiliate created successfully'
            ], 201);
        } catch (\Throwable $th) {
            Log::error('Affiliate create error: ' . $th->getMessage());
            return response()->json([
                'message' => 'Failed to create affiliate',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'affiliate_id' => 'required|exists:affiliates,id',
            'state' => 'nullable|string|max:100',  // Only state name, no state_id
            'employer_name' => 'nullable|string|max:255',
            'ein' => 'nullable|string|max:20',
            'affiliation_date' => 'nullable|date',
            'affiliate_type' => 'nullable|in:Associate,Professional,Wall-to-Wall',
            'cbc_region' => 'nullable|in:Northeast,Cooridor,South,Central,Western',
            'ORG_region' => 'nullable|string|in:1,2,3,4,5,6,7',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
        ]);

        try {
            $affiliate = DB::transaction(function () use ($validated, $request) {
                $affiliate = Affiliate::find($validated['affiliate_id']);

                if ($request->hasFile('logo')) {
                    $file = $request->file('logo');

                    if ($affiliate->logo_path) {
                        try {
                            Storage::disk('supabase')->delete($affiliate->logo_path);
                        } catch (\Exception $e) {
                            Log::warning('Failed to delete old profile photo: ' . $e->getMessage());
                        }
                    }

                    $extension = $file->getClientOriginalExtension();
                    $fileName = 'affiliate_logo_' . time() . '_' . Str::random(10) . '.' . $extension;
                    $filePath = 'affiliate-logos/' . $fileName;
                    Storage::disk('supabase')->put($filePath, file_get_contents($file));
                    $validated['logo_path'] = $filePath;
                    $max_expires_at = now()->addWeek();
                    $validated['logo_signed_url'] = Storage::disk('supabase')->temporaryUrl($filePath, $max_expires_at);
                    $validated['logo_signed_expires_at'] = $max_expires_at->subDay();
                }

                $affiliate->update($validated);

                DocumentFolder::firstOrCreate([
                    'affiliate_id' => $affiliate->id,
                    'root' => true,
                ], [
                    'folder_name' => null
                ]);

                return $affiliate;
            });

            Cache::forget('affiliate_employers_options');
            Cache::forget('affiliate_employers_options_' . $validated['affiliate_id']);

            return response()->json([
                'success' => true,
                'data'    => $affiliate,
                'message' => 'affiliate updated successfully'
            ], 201);
        } catch (\Throwable $th) {
            Log::error('Affiliate update error: ' . $th->getMessage());
            return response()->json([
                'message' => 'Failed to update affiliate',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function delete($id)
    {
        try {
            $affiliate = Affiliate::find($id);

            if (!$affiliate) {
                return response()->json([
                    'message' => 'Affiliate not found.'
                ], 404);
            }

            // Delete logo if exists
            if ($affiliate->logo_path) {
                try {
                    Storage::disk('supabase')->delete($affiliate->logo_path);
                } catch (\Exception $e) {
                    Log::warning('Failed to delete affiliate logo: ' . $e->getMessage());
                }
            }

            $affiliate->delete();

            return response()->json([
                'message' => 'Affiliate deleted successfully.',
                'affiliate' => $affiliate,
            ]);
        } catch (\Throwable $th) {
            Log::error('Affiliate delete error: ' . $th->getMessage());
            return response()->json([
                'message' => 'Failed to delete affiliate',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function options(Request $request)
    {
        $search = $request->query('search', null);
        $affiliate_id = $request->query('affiliate_id');

        // Load user and relationships
        $user = Auth::user()->load('roles.permissions');

        // Get role names as array
        $userRoles = $user->roles->pluck('name')->toArray();

        // Define global roles that bypass region
        $globalRoles = [
            RoleEnum::NATIONAL_ADMINISTRATOR->value,
            RoleEnum::ORG_EXECUTIVE_COMMITEE->value,
            RoleEnum::ORG_RESEARCH_COMMITEE->value,
        ];

        // Check if the user has any global role
        $hasGlobalRole = count(array_intersect($userRoles, $globalRoles)) > 0;

        if ($hasGlobalRole) {
            // Global role → no region filtering
            $regions = null;
        } else {
            // Only regional roles → extract all regions
            $regions = collect($userRoles)
                ->filter(fn($r) => str_starts_with($r, 'region_'))
                ->map(function ($role) {
                    preg_match('/region_(\d+)_director/', $role, $matches);
                    return $matches[1] ?? null;
                })
                ->filter() // remove nulls
                ->values() // reindex
                ->all();
        }

        $extra_default = null;
        if ($affiliate_id) {
            $extra_default = Affiliate::where('id', $affiliate_id)->first();
        }

        // Build the query
        $query = Affiliate::query();

        // Apply region filter if regions exist
        $query->when($regions, fn($q) => $q->whereIn('ORG_region', $regions));

        // Apply search if present
        $query->when($search, fn($q) => $q->where('name', 'ilike', "%{$search}%")); // Postgres ilike for case-insensitive

        $query->orderBy('name');

        // Limit to 15 results
        $options = $query->limit(15)->get();

        // Prepend default if it exists AND is not already in the options
        if ($extra_default && ! $options->contains('id', $extra_default->id)) {
            $options->prepend($extra_default);
        }


        return response()->json($options, 200);
    }

    public function allAffiliates()
    {
        $all_affiliates = Affiliate::select('id', 'name', 'public_uid')
            ->orderBy('name', 'asc')
            ->get();

        return response()->json($all_affiliates, 200);
    }

    public function index(Request $request)
    {
        $search = $request->query('search');
        $query = Affiliate::query()
            ->withCount([
                'members as members_count',
                'members as associate_count' => function ($query) {
                    $query->where('level', 'Associate');
                },
                'members as professional_count' => function ($query) {
                    $query->where('level', 'Professional');
                }
            ]);

        if ($search) {
            $query->search($search);
        }

        $query->baseFilter($request);

        $sort_by = $request->query('sort_by', 'name');
        $sort_order = $request->query('sort_order', 'asc');
        $per_page = $request->query('per_page', 20);

        return ApiCollection::makeFromQuery($query, $per_page, $sort_by, $sort_order)
            ->useResource(AffiliateResource::class);
    }

    public function remove(Request $request)
    {
        $validated = $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['exists:affiliates,id'],
        ]);

        try {
            DB::transaction(function () use ($validated) {
                $affiliates = Affiliate::whereIn('id', $validated['ids'])->get();
                foreach ($affiliates as $affiliate) {
                    if ($affiliate->logo_path) {
                        try {
                            Storage::disk('supabase')->delete($affiliate->logo_path);
                        } catch (\Throwable $e) {
                            Log::warning(
                                'Failed to delete affiliate logo',
                                [
                                    'affiliate_id' => $affiliate->id,
                                    'path' => $affiliate->logo_path,
                                    'error' => $e->getMessage(),
                                ]
                            );
                        }
                    }
                    $affiliate->delete();
                }
            });
            return response()->json([
                'success' => true,
                'message' => 'Affiliate successfully removed',
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Affiliate removal failed', [
                'ids' => $validated['ids'],
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Something went wrong. Please try again.',
            ], 500);
        }
    }

    public function export(Request $request)
    {
        $search = $request->query('search');
        $ids = $request->query('ids');

        $affiliate_ids = is_array($ids) ? $ids : explode(',', $ids);

        $query = Affiliate::query()
            ->withCount([
                'members as members_count',
                'members as associate_count' => function ($query) {
                    $query->where('level', 'Associate');
                },
                'members as professional_count' => function ($query) {
                    $query->where('level', 'Professional');
                }
            ]);

        if ($search) {
            $query->search($search);
        }

        $query->baseFilter($request);

        if ($ids) {
            $query->whereIn('id', $affiliate_ids);
        }

        $sort_by = $request->query('sort_by', 'name');
        $sort_order = $request->query('sort_order', 'asc');
        $per_page = 'All';

        return ApiCollection::makeFromQuery($query, $per_page, $sort_by, $sort_order)
            ->useResource(AffiliateResource::class);
    }

    public function employers()
    {
        $user = Auth::user();
        $affiliate_id = null;

        if (!$user->hasRole(RoleEnum::nationalRoles())) {
            $member = Member::where('user_id', $user->id)->first();
            if (!$member?->affiliate_id) return response()->json([]);
            $affiliate_id = $member->affiliate_id;
        }

        $key = $affiliate_id
            ? "affiliate_employers_options_" . $affiliate_id
            : "affiliate_employers_options";

        $employers = Cache::remember($key, 3600, function () use ($affiliate_id) {
            return Affiliate::query()
                ->when($affiliate_id, fn($q) => $q->where('id', $affiliate_id)) // filter by affiliate
                ->whereNotNull('employer_name')
                ->whereNotIn('employer_name', ['N/A'])
                ->distinct()
                ->orderBy('employer_name')
                ->pluck('employer_name')
                ->mapWithKeys(fn($name) => [
                    Str::slug($name) => $name
                ])
                ->toArray();
        });

        $response = collect($employers)->map(fn($name, $slug) => [
            'value' => $slug,
            'label' => $name
        ])->values();

        return response()->json($response);
    }
}
