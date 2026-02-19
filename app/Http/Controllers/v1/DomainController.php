<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\Domain;
use App\Models\Link;
use App\Models\Member;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class DomainController extends Controller
{
    public function domains(Request $request)
    {
        $affiliate_id = Member::where('user_id', Auth::user()->id)->first()->affiliate_id;

        $query = Domain::query();
        $query->where('affiliate_id', $affiliate_id);

        if ($search = $request->query('search')) {
            $query->search($search);
        }

        $perPage = (int) $request->query('per_page', 20);

        // For large exports, return all data without pagination
        if ($perPage > 'All') {
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
    }

    public function affiliateDomains($id, Request $request)
    {
        $query = Domain::query()
            ->whereHas('affiliate', function ($q) use ($id) {
                $q->where('public_uid', $id);
            });

        if ($search = $request->query('search')) {
            $query->search($search);
        }

        $perPage = (int) $request->query('per_page', 20);

        // For large exports, return all data without pagination
        if ($perPage > 'All') {
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
    }

    public function blackListed(Request $request)
    {
        $affiliate_id = Member::where('user_id', Auth::user()->id)->first()->affiliate_id;

        $query = Domain::query();
        $query->where('affiliate_id', $affiliate_id)
            ->where('is_blacklisted', true);

        if ($search = $request->query('search')) {
            $query->search($search);
        }

        $perPage = (int) $request->query('per_page', 20);

        // For large exports, return all data without pagination
        if ($perPage > 'All') {
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
    }

    public function block(Request $request)
    {
        $validated = $request->validate([
            'domain' => [
                'required',
                'string',
                'regex:/^(?!-)([a-zA-Z0-9-]+\.)+[a-zA-Z]{2,}$/'
            ],
        ], [
            'domain.regex' => 'Please enter a valid domain (e.g., example.com).',
        ]);

        $affiliate_id = Member::where('user_id', Auth::user()->id)->first()->affiliate_id;
        $validated['is_blacklisted'] = true;
        $validated['affiliate_id'] = $affiliate_id;

        $domain = Domain::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Domain has been blacklisted successfully.',
            'data' => $domain
        ], 201);
    }

    public function create(Request $request)
    {
        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(['domain', 'tld'])],
            'domain' => [
                'required',
                'string',
                function ($attribute, $value, $fail) use ($request) {
                    $type = $request->input('type');

                    if ($type === 'domain') {
                        if (!preg_match('/^(?!-)([a-zA-Z0-9-]+\.)+[a-zA-Z]{2,}$/', $value)) {
                            $fail('Please enter a valid full domain (e.g., example.com).');
                        }
                    } elseif ($type === 'tld') {
                        if (!preg_match('/^\.[a-zA-Z]{2,}$/', $value)) {
                            $fail('Please enter a valid TLD (e.g., .org, .com).');
                        }
                    }
                }
            ],
            'status' => ['required', 'string', Rule::in(['block', 'allow'])],
            'affiliate_id' => ['nullable', 'exists:affiliates,public_uid']
        ]);
        $affiliate_id = Affiliate::where('public_uid', $validated['affiliate_id'])->value('id');
        $validated['affiliate_id'] = $affiliate_id;
        $validated['is_blacklisted'] = $validated['status'] === 'block';
        unset($validated['status']);

        $domain = Domain::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Domain has been blacklisted successfully.',
            'data' => $domain
        ], 201);
    }


    public function delete(Request $request)
    {
        $validated = $request->validate([
            'domain_id' => 'required|exists:domains,id'
        ]);

        $domain = Domain::find($validated['domain_id']);
        $domain->delete();

        return response()->json([
            'success' => true,
            'message' => 'Domain has been remove successfully.',
            'data' => $domain
        ], 201);
    }
}
