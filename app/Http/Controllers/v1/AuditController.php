<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityLogResource;
use App\Http\Resources\ApiCollection;
use App\Models\ActivityLog;
use App\Models\Affiliate;
use App\Models\Member;
use App\Models\OfficerPosition;
use App\Models\Role;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function logs(Request $request)
    {
        $perPage = $request->query('per_page', 20);
        $sortBy = $request->query('sort_by', 'created_at');
        $sortOrder = $request->query('sort_order', 'desc');
        $action = $request->query('action', null);
        $affiliate = $request->query('affiliate', null);
        $type = match ($request->query('audit_type')) {
            'affiliate' => [Affiliate::class],
            'member' => [Member::class],
            'role' => [Role::class],
            'officer' => [OfficerPosition::class],
            default => [Member::class, Affiliate::class, Role::class, OfficerPosition::class]
        };

        $query = ActivityLog::query()
            ->with(['user:id,name', 'affiliate:id,name', 'auditable'])
            ->whereIn('auditable_type', $type)
            ->when($action, fn($q) => $q->where('action', $action))
            ->when($affiliate, function ($q) use ($affiliate) {
                $q->where(function ($q2) use ($affiliate) {
                    $q2->where(function ($q3) use ($affiliate) {
                        $q3->where('auditable_type', Member::class)
                            ->whereHas('auditable', fn($q4) => $q4->where('affiliate_id', $affiliate));
                    })
                        ->orWhere(function ($q3) use ($affiliate) {
                            $q3->where('auditable_type', Affiliate::class)
                                ->where('auditable_id', $affiliate); // logs for the affiliate itself
                        });
                });
            });

        return ApiCollection::makeFromQuery($query, $perPage, $sortBy, $sortOrder)
            ->useResource(ActivityLogResource::class);
    }
}
