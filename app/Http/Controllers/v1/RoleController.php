<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\AffiliateOfficer;
use App\Models\Member;
use App\Models\OfficerHistory;
use App\Models\OfficerPosition;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RoleController extends Controller
{

    public function history($id, Request $request)
    {
        $type = $request->query('type');
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

        try {

            $query = match ($type) {
                'national' => OfficerHistory::query()
                    ->where('entity_id', $id)
                    ->whereNotNull('end_date')
                    ->where('entity_type', Role::class)
                    ->with('entity', 'user'),
                'affiliate' => AffiliateOfficer::where('is_vacant', 0)
                    ->where('position_id', $id)
                    ->where('affiliate_id', $affiliate_id)
                    ->where('end_date', '<=', now())
                    ->whereHas('member')
                    ->with(['member.user'])
                    ->orderBy('end_date', 'desc'),
            };

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
