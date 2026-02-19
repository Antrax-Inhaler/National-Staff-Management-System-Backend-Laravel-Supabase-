<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class OrganizationDashboardController extends Controller
{
    // Common filter method used by all endpoints - REMOVED date_of_hire filter
// Updated applyMemberFilters method
   private function applyMemberFilters($query, $filters)
    {
        $query->when($filters['affiliate_id'] ?? null, function ($q) use ($filters) {
            // Handle multiple affiliate IDs (array) or single ID
            if (is_array($filters['affiliate_id'])) {
                return $q->whereIn('m.affiliate_id', $filters['affiliate_id']);
            }
            return $q->where('m.affiliate_id', $filters['affiliate_id']);
        })
        ->when($filters['member_level'] ?? null, function ($q) use ($filters) {
            if ($filters['member_level'] === 'Not Specified') {
                return $q->whereNull('m.level');
            }
            return $q->where('m.level', $filters['member_level']);
        })
        ->when($filters['state'] ?? null, function ($q) use ($filters) {
            // Always handle as array for multi-select
            $states = is_array($filters['state']) ? $filters['state'] : [$filters['state']];
            
            if (in_array('Not Specified', $states)) {
                return $q->where(function($subQ) use ($states) {
                    $filteredStates = array_filter($states, fn($s) => $s !== 'Not Specified');
                    if (!empty($filteredStates)) {
                        $subQ->whereIn('m.state', $filteredStates);
                    }
                    $subQ->orWhereNull('m.state');
                });
            }
            
            return $q->whereIn('m.state', $states);
        })
        ->when($filters['affiliate_type'] ?? null, function ($q) use ($filters) {
            $affiliateTypes = is_array($filters['affiliate_type']) 
                ? $filters['affiliate_type'] 
                : [$filters['affiliate_type']];
                
            return $q->join('affiliates as a', 'm.affiliate_id', '=', 'a.id')
                    ->whereIn('a.affiliate_type', $affiliateTypes);
        })
        ->when($filters['cbc_region'] ?? null, function ($q) use ($filters) {
            $cbcRegions = is_array($filters['cbc_region']) 
                ? $filters['cbc_region'] 
                : [$filters['cbc_region']];
                
            return $q->join('affiliates as a', 'm.affiliate_id', '=', 'a.id')
                    ->where(function($subQ) use ($cbcRegions) {
                        if (in_array('Not Specified', $cbcRegions)) {
                            $filteredRegions = array_filter($cbcRegions, fn($r) => $r !== 'Not Specified');
                            if (!empty($filteredRegions)) {
                                $subQ->whereIn('a.cbc_region', $filteredRegions);
                            }
                            $subQ->orWhereNull('a.cbc_region');
                        } else {
                            $subQ->whereIn('a.cbc_region', $cbcRegions);
                        }
                    });
        })
        ->when($filters['org_region'] ?? null, function ($q) use ($filters) {
            $nsoRegions = is_array($filters['org_region']) 
                ? $filters['org_region'] 
                : [$filters['org_region']];
                
            return $q->join('affiliates as a', 'm.affiliate_id', '=', 'a.id')
                    ->where(function($subQ) use ($nsoRegions) {
                        if (in_array('Not Specified', $nsoRegions)) {
                            $filteredRegions = array_filter($nsoRegions, fn($r) => $r !== 'Not Specified');
                            if (!empty($filteredRegions)) {
                                $subQ->whereIn('a.org_region', $filteredRegions);
                            }
                            $subQ->orWhereNull('a.org_region');
                        } else {
                            $subQ->whereIn('a.org_region', $nsoRegions);
                        }
                    });
        });
        
        return $query;
    }


    // NEW: Executive Summary Endpoint
    public function getExecutiveSummary(Request $request)
    {
        try {
            $filters = $this->prepareFilters($request);
            
            $data = [
                'key_metrics' => $this->getExecutiveMetrics($filters),
                'cards_data' => $this->getTopCardsData($filters),
                'trend_indicators' => $this->getTrendIndicators($filters)
            ];

            return response()->json([
                'success' => true,
                'data' => $data,
                'message' => 'Executive summary retrieved successfully',
                'filters' => $filters['applied']
            ]);
        } catch (\Exception $e) {
            Log::error('Executive summary error: ' . $e->getMessage());
            return $this->errorResponse($e);
        }
    }

    // NEW: Demographic Analysis Endpoint
    public function getDemographicAnalysis(Request $request)
    {
        try {
            $filters = $this->prepareFilters($request);
            
            $data = [
                'state_distribution' => $this->getStateDistribution($filters),
                'gender_diversity' => $this->getGenderDistribution($filters),
                'employment_status' => $this->getEmploymentStatus($filters),
                'member_levels' => $this->getMemberLevelDistribution($filters),
                'ethnicity_self_id' => $this->getEthnicityDistribution($filters)
            ];

            return response()->json([
                'success' => true,
                'data' => $data,
                'message' => 'Demographic analysis retrieved successfully',
                'filters' => $filters['applied']
            ]);
        } catch (\Exception $e) {
            Log::error('Demographic analysis error: ' . $e->getMessage());
            return $this->errorResponse($e);
        }
    }

    // NEW: Affiliate Analytics Endpoint
    public function getAffiliateAnalytics(Request $request)
    {
        try {
            $filters = $this->prepareFilters($request);
            
            $data = [
                'members_per_affiliate' => $this->getTopAffiliatesByMembers($filters, 10),
                'affiliate_types_distribution' => $this->getAffiliateTypeDistribution($filters),
                'regional_distribution' => $this->getRegionalDistribution($filters),
                'affiliate_growth_timeline' => $this->getAffiliateGrowthTimeline($filters)
            ];

            return response()->json([
                'success' => true,
                'data' => $data,
                'message' => 'Affiliate analytics retrieved successfully',
                'filters' => $filters['applied']
            ]);
        } catch (\Exception $e) {
            Log::error('Affiliate analytics error: ' . $e->getMessage());
            return $this->errorResponse($e);
        }
    }

    // NEW: Temporal Analysis Endpoint
    public function getTemporalAnalysis(Request $request)
    {
        try {
            $filters = $this->prepareFilters($request);
            
            $data = [
                'member_growth_timeline' => $this->getMemberGrowthTimeline($filters),
                'hiring_trends' => $this->getHiringTrendsByYear($filters),
                'age_distribution' => $this->getAgeDistribution($filters),
                'tenure_analysis' => $this->getTenureAnalysis($filters)
            ];

            return response()->json([
                'success' => true,
                'data' => $data,
                'message' => 'Temporal analysis retrieved successfully',
                'filters' => $filters['applied']
            ]);
        } catch (\Exception $e) {
            Log::error('Temporal analysis error: ' . $e->getMessage());
            return $this->errorResponse($e);
        }
    }

    // NEW: System & Governance Endpoint
public function getSystemGovernance(Request $request)
{
    try {
        $filters = $this->prepareFilters($request);
        
        $data = [
            'recent_updates' => (new AuditController())->getRecentSystemUpdates($request)->getData()->data,
            'document_uploads' => $this->getDocumentUploadsStats($filters),
            'officer_assignments' => $this->getOfficerAssignmentsStats($filters),
            'compliance_status' => $this->getComplianceStatus($filters),
            'data_quality_score' => $this->getDataQualityScore($filters)
        ];

        return response()->json([
            'success' => true,
            'data' => $data,
            'message' => 'System governance data retrieved successfully',
            'filters' => $filters['applied']
        ]);
    } catch (\Exception $e) {
        Log::error('System governance error: ' . $e->getMessage());
        return $this->errorResponse($e);
    }
}

    // NEW: Research & Governance Endpoint
    public function getResearchGovernance(Request $request)
    {
        try {
            $filters = $this->prepareFilters($request);
            
            $data = [
                'document_categories' => $this->getDocumentCategoriesSplit($filters),
                'contract_arbitration_tracking' => $this->getContractArbitrationStats($filters),
                'compliance_dashboard' => $this->getComplianceDashboard($filters),
                'data_quality_details' => $this->getDataQualityDetails($filters)
            ];

            return response()->json([
                'success' => true,
                'data' => $data,
                'message' => 'Research governance data retrieved successfully',
                'filters' => $filters['applied']
            ]);
        } catch (\Exception $e) {
            Log::error('Research governance error: ' . $e->getMessage());
            return $this->errorResponse($e);
        }
    }

    // UPDATED: Original index method (now modular)
    public function index(Request $request)
    {
        try {
            $filters = $this->prepareFilters($request);
            
            // For backward compatibility, return combined data
            $dashboardData = [
                'executive_summary' => $this->getExecutiveMetrics($filters),
                'demographic_analysis' => [
                    'state_distribution' => $this->getStateDistribution($filters),
                    'gender_diversity' => $this->getGenderDistribution($filters),
                    'employment_status' => $this->getEmploymentStatus($filters)
                ],
                'membership_analytics' => $this->getMembershipAnalytics($filters),
                'affiliate_analytics' => $this->getTopAffiliatesByMembers($filters, 5),
                'temporal_analysis' => $this->getMemberGrowthTimeline($filters),
                'directory_data' => $this->getDirectoryData($filters['affiliate_id']),
                'filter_options' => $this->getFilterOptions()
            ];

            return response()->json([
                'success' => true,
                'data' => $dashboardData,
                'message' => 'Organization dashboard data retrieved successfully',
                'filters_applied' => $filters['applied']
            ]);
        } catch (\Exception $e) {
            Log::error('Organization dashboard error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load national dashboard data',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    // Helper method to prepare all filters - REMOVED dateFilter for non-temporal functions
private function prepareFilters(Request $request)
{
    $timeRange = $request->get('time_range', 'last_12_months');
    $affiliateId = $request->get('affiliate_id');
    $memberLevel = $request->get('member_level');
    $state = $request->get('state', []); // Default to empty array
    $startDate = $request->get('start_date');
    $endDate = $request->get('end_date');
    $affiliateType = $request->get('affiliate_type', []);
    $cbcRegion = $request->get('cbc_region', []);
    $nsoRegion = $request->get('org_region', []);
    
    // Always ensure arrays for multi-select filters
    $state = is_array($state) ? $state : ($state ? [$state] : []);
    $affiliateType = is_array($affiliateType) ? $affiliateType : ($affiliateType ? [$affiliateType] : []);
    $cbcRegion = is_array($cbcRegion) ? $cbcRegion : ($cbcRegion ? [$cbcRegion] : []);
    $nsoRegion = is_array($nsoRegion) ? $nsoRegion : ($nsoRegion ? [$nsoRegion] : []);
    
    // For affiliate_id, keep as is (could be single or array)
    if ($affiliateId && !is_array($affiliateId)) {
        $affiliateId = [$affiliateId];
    }

    return [
        'affiliate_id' => $affiliateId,
        'member_level' => $memberLevel,
        'state' => $state,
        'affiliate_type' => $affiliateType,
        'cbc_region' => $cbcRegion,
        'org_region' => $nsoRegion,
        'time_range' => $timeRange,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'applied' => [
            'time_range' => $timeRange,
            'affiliate_id' => $affiliateId,
            'member_level' => $memberLevel,
            'state' => $state,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'affiliate_type' => $affiliateType,
            'cbc_region' => $cbcRegion,
            'org_region' => $nsoRegion
        ]
    ];
}
// Add this method to your controller
 public function getAffiliateNames(Request $request)
    {
        try {
            $schema = config('database.connections.pgsql.search_path');
            
            $searchTerm = $request->get('search', '');
            $limit = $request->get('limit', 100);
            $affiliateType = $request->get('affiliate_type');
            
            $query = DB::table($schema . '.affiliates')
                ->select('id', 'name', 'affiliate_type', 'state')
                ->whereNotNull('name')
                ->where('name', '!=', '');
            
            if ($searchTerm) {
                $query->where('name', 'ilike', "%{$searchTerm}%");
            }
            
            if ($affiliateType && is_array($affiliateType)) {
                $query->whereIn('affiliate_type', $affiliateType);
            } elseif ($affiliateType) {
                $query->where('affiliate_type', $affiliateType);
            }
            
            $affiliates = $query->orderBy('name')
                ->limit($limit)
                ->get()
                ->map(function ($affiliate) {
                    return [
                        'value' => $affiliate->id,
                        'label' => $affiliate->name,
                        'type' => $affiliate->affiliate_type,
                        'state' => $affiliate->state
                    ];
                })
                ->toArray();

            return response()->json([
                'success' => true,
                'data' => $affiliates,
                'message' => 'Affiliate names retrieved successfully',
                'total' => count($affiliates)
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error in getAffiliateNames: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve affiliate names',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
  private function getExecutiveMetrics($filters)
    {
        try {
            $schema = config('database.connections.pgsql.search_path');

            // Total Members - NO date_of_hire filter
            $totalMembers = DB::table($schema . '.members as m')
                ->when($filters['affiliate_id'], function ($q) use ($filters) {
                    return $q->where('m.affiliate_id', $filters['affiliate_id']);
                })
                ->count();

            // Active/Inactive Ratio - NO date_of_hire filter
            $statusCounts = DB::table($schema . '.members as m')
                ->select(
                    DB::raw("COALESCE(m.status, 'Not Specified') as status"),
                    DB::raw('COUNT(*) as count')
                )
                ->when($filters['affiliate_id'], function ($q) use ($filters) {
                    return $q->where('m.affiliate_id', $filters['affiliate_id']);
                })
                ->groupBy(DB::raw("COALESCE(m.status, 'Not Specified')"))
                ->get();

            $activeCount = $statusCounts->where('status', 'Active')->first()->count ?? 0;
            $inactiveCount = $totalMembers - $activeCount;
            $activeRatio = $totalMembers > 0 ? round(($activeCount / $totalMembers) * 100, 1) : 0;
            $inactiveRatio = $totalMembers > 0 ? round(($inactiveCount / $totalMembers) * 100, 1) : 0;

            // Affiliate Organizations count
            $totalAffiliates = DB::table($schema . '.affiliates as a')
                ->when($filters['affiliate_id'], function ($q) use ($filters) {
                    return $q->where('a.id', $filters['affiliate_id']);
                })
                ->when($filters['affiliate_type'], function ($q) use ($filters) {
                    return $q->where('a.affiliate_type', $filters['affiliate_type']);
                })
                ->count();

            // New Members (Last 30 Days) - Use created_at instead of date_of_hire
            $newMembers = DB::table($schema . '.members as m')
                ->where('m.created_at', '>=', now()->subDays(30))
                ->when($filters['affiliate_id'], function ($q) use ($filters) {
                    return $q->where('m.affiliate_id', $filters['affiliate_id']);
                })
                ->count();

            // Pending Actions - from activity logs (last 7 days)
            $pendingActions = Schema::hasTable('activity_log') 
                ? DB::table($schema . '.activity_log')
                    ->where('created_at', '>=', now()->subDays(7))
                    ->whereIn('event', ['created', 'updated', 'deleted'])
                    ->count()
                : 0;

            return [
                'total_members' => (int)$totalMembers,
                'active_inactive_ratio' => [
                    'active' => $activeRatio,
                    'inactive' => $inactiveRatio,
                    'active_count' => (int)$activeCount,
                    'inactive_count' => (int)$inactiveCount
                ],
                'total_affiliates' => (int)$totalAffiliates,
                'new_members_last_30_days' => (int)$newMembers,
                'pending_actions' => (int)$pendingActions
            ];
        } catch (\Exception $e) {
            Log::error('Error in getExecutiveMetrics: ' . $e->getMessage());
            return $this->getDefaultMetrics();
        }
    }


    private function getTopCardsData($filters)
    {
        // Additional card data like trends, comparisons, etc.
        return [
            'members_trend' => $this->getMemberTrendComparison($filters),
            'affiliates_trend' => $this->getAffiliateTrendComparison($filters),
            'engagement_rate' => $this->calculateEngagementRate($filters)
        ];
    }

    private function getTrendIndicators($filters)
    {
        // Calculate trend indicators (up/down arrows)
        $currentPeriod = $this->getExecutiveMetrics($filters);
        
        // For trends, compare with data from previous period (30 days ago)
        $previousPeriodData = $this->getExecutiveMetricsForPeriod($filters, now()->subDays(60), now()->subDays(30));

        return [
            'members_trend' => $this->calculateTrend(
                $currentPeriod['total_members'], 
                $previousPeriodData['total_members']
            ),
            'active_members_trend' => $this->calculateTrend(
                $currentPeriod['active_inactive_ratio']['active_count'], 
                $previousPeriodData['active_inactive_ratio']['active_count']
            ),
            'new_members_trend' => $this->calculateTrend(
                $currentPeriod['new_members_last_30_days'], 
                $previousPeriodData['new_members_last_30_days']
            )
        ];
    }
private function getStateDistribution($filters)
    {
        $schema = config('database.connections.pgsql.search_path');
        
        $query = DB::table($schema . '.members as m')
            ->select(
                DB::raw("COALESCE(m.state, 'Not Specified') as state"),
                DB::raw('COUNT(*) as count')
            )
            ->when($filters['affiliate_id'], function ($q) use ($filters) {
                return $q->where('m.affiliate_id', $filters['affiliate_id']);
            });

        return $this->applyMemberFilters($query, $filters)
            ->groupBy(DB::raw("COALESCE(m.state, 'Not Specified')"))
            ->orderBy('count', 'desc')
            ->limit(15)
            ->get()
            ->map(function ($item) {
                return [
                    'state' => $item->state,
                    'count' => (int)$item->count
                ];
            })
            ->toArray();
    }


private function getGenderDistribution($filters)
    {
        $schema = config('database.connections.pgsql.search_path');
        
        $query = DB::table($schema . '.members as m')
            ->select(
                DB::raw("CASE 
                    WHEN LOWER(TRIM(gender)) = 'male' THEN 'Male'
                    WHEN LOWER(TRIM(gender)) = 'female' THEN 'Female'
                    WHEN gender IS NULL OR gender = '' THEN 'Not Specified'
                    ELSE 'Other'
                END as gender"),
                DB::raw('COUNT(*) as count')
            );

        $genderData = $this->applyMemberFilters($query, $filters)
            ->groupBy(DB::raw("CASE 
                WHEN LOWER(TRIM(gender)) = 'male' THEN 'Male'
                WHEN LOWER(TRIM(gender)) = 'female' THEN 'Female'
                WHEN gender IS NULL OR gender = '' THEN 'Not Specified'
                ELSE 'Other'
            END"))
            ->get();

        $total = $genderData->sum('count');
        
        return $genderData->map(function ($item) use ($total) {
            $percentage = $total > 0 ? round(($item->count / $total) * 100, 1) : 0;
            return [
                'gender' => $item->gender,
                'count' => (int)$item->count,
                'percentage' => $percentage
            ];
        })->toArray();
    }


 private function getEmploymentStatus($filters)
    {
        $schema = config('database.connections.pgsql.search_path');
        
        $query = DB::table($schema . '.members as m')
            ->select(
                DB::raw("COALESCE(m.employment_status, 'Not Specified') as status"),
                DB::raw('COUNT(*) as count')
            );

        return $this->applyMemberFilters($query, $filters)
            ->groupBy(DB::raw("COALESCE(m.employment_status, 'Not Specified')"))
            ->orderBy('count', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    'status' => $item->status,
                    'count' => (int)$item->count
                ];
            })
            ->toArray();
    }
 private function getMemberLevelDistribution($filters)
    {
        $schema = config('database.connections.pgsql.search_path');
        
        $query = DB::table($schema . '.members as m')
            ->select(
                DB::raw("COALESCE(m.level, 'Not Specified') as level"),
                DB::raw('COUNT(*) as count')
            );

        return $this->applyMemberFilters($query, $filters)
            ->groupBy(DB::raw("COALESCE(m.level, 'Not Specified')"))
            ->orderBy('count', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    'level' => $item->level,
                    'count' => (int)$item->count
                ];
            })
            ->toArray();
    }

  private function getEthnicityDistribution($filters)
    {
        $schema = config('database.connections.pgsql.search_path');
        
        $query = DB::table($schema . '.members as m')
            ->select(
                DB::raw("COALESCE(m.self_id, 'Not Specified') as self_id"),
                DB::raw('COUNT(*) as count')
            );

        return $this->applyMemberFilters($query, $filters)
            ->groupBy(DB::raw("COALESCE(m.self_id, 'Not Specified')"))
            ->orderBy('count', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($item) {
                return [
                    'self_id' => $item->self_id,
                    'count' => (int)$item->count
                ];
            })
            ->toArray();
    }

  private function getTopAffiliatesByMembers($filters, $limit = 10)
    {
        $schema = config('database.connections.pgsql.search_path');
        
        return DB::table($schema . '.affiliates as a')
            ->leftJoin($schema . '.members as m', 'a.id', '=', 'm.affiliate_id')
            ->select(
                'a.id',
                'a.public_uid',
                'a.name as affiliate_name',
                'a.affiliate_type',
                DB::raw('COUNT(m.id) as total_members'),
                DB::raw('SUM(CASE WHEN m.status = \'Active\' THEN 1 ELSE 0 END) as active_members')
            )
            ->when($filters['affiliate_id'], function ($q) use ($filters) {
                return $q->where('a.id', $filters['affiliate_id']);
            })
            ->when($filters['affiliate_type'], function ($q) use ($filters) {
                return $q->where('a.affiliate_type', $filters['affiliate_type']);
            })
            ->groupBy('a.id', 'a.name', 'a.affiliate_type', 'a.public_uid')
            ->orderBy('total_members', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($affiliate) {
                $totalMembers = $affiliate->total_members ?? 0;
                $activeMembers = $affiliate->active_members ?? 0;
                $engagementRate = $totalMembers > 0 
                    ? round(($activeMembers / $totalMembers) * 100, 1) 
                    : 0;

                return [
                    'affiliate_id' => $affiliate->id,
                    'public_uid' => $affiliate->public_uid,
                    'affiliate_name' => $affiliate->affiliate_name,
                    'affiliate_type' => $affiliate->affiliate_type,
                    'total_members' => (int)$totalMembers,
                    'active_members' => (int)$activeMembers,
                    'engagement_rate' => $engagementRate
                ];
            })
            ->toArray();
    }

 private function getAffiliateTypeDistribution($filters)
    {
        $schema = config('database.connections.pgsql.search_path');
        
        return DB::table($schema . '.affiliates as a')
            ->leftJoin($schema . '.members as m', 'a.id', '=', 'm.affiliate_id')
            ->select(
                'a.affiliate_type',
                DB::raw('COUNT(DISTINCT a.id) as affiliate_count'),
                DB::raw('COUNT(m.id) as member_count')
            )
            ->when($filters['affiliate_id'], function ($q) use ($filters) {
                return $q->where('a.id', $filters['affiliate_id']);
            })
            ->when($filters['affiliate_type'], function ($q) use ($filters) {
                return $q->where('a.affiliate_type', $filters['affiliate_type']);
            })
            ->whereNotNull('a.affiliate_type')
            ->groupBy('a.affiliate_type')
            ->orderBy('member_count', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    'affiliate_type' => $item->affiliate_type,
                    'affiliate_count' => (int)$item->affiliate_count,
                    'member_count' => (int)$item->member_count
                ];
            })
            ->toArray();
    }
 private function getRegionalDistribution($filters)
    {
        $schema = config('database.connections.pgsql.search_path');
        
        return DB::table($schema . '.affiliates as a')
            ->leftJoin($schema . '.members as m', 'a.id', '=', 'm.affiliate_id')
            ->select(
                DB::raw("COALESCE(a.cbc_region, 'Not Specified') as cbc_region"),
                DB::raw('COUNT(DISTINCT a.id) as affiliate_count'),
                DB::raw('COUNT(m.id) as member_count')
            )
            ->when($filters['affiliate_id'], function ($q) use ($filters) {
                return $q->where('a.id', $filters['affiliate_id']);
            })
            ->when($filters['affiliate_type'], function ($q) use ($filters) {
                return $q->where('a.affiliate_type', $filters['affiliate_type']);
            })
            ->groupBy(DB::raw("COALESCE(a.cbc_region, 'Not Specified')"))
            ->orderBy('member_count', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    'cbc_region' => $item->cbc_region,
                    'affiliate_count' => (int)$item->affiliate_count,
                    'member_count' => (int)$item->member_count
                ];
            })
            ->toArray();
    }

 private function getAffiliateGrowthTimeline($filters)
    {
        $schema = config('database.connections.pgsql.search_path');
        
        // Use created_at instead of date_of_hire for growth timeline
        $startDate = Carbon::parse(now()->subMonths(11))->startOfMonth();
        $endDate = Carbon::parse(now())->endOfMonth();

        return DB::table($schema . '.members as m')
            ->join($schema . '.affiliates as a', 'm.affiliate_id', '=', 'a.id')
            ->select(
                DB::raw("DATE_TRUNC('month', m.created_at) as month"),
                'a.name as affiliate_name',
                DB::raw('COUNT(m.id) as new_members')
            )
            ->when($filters['affiliate_id'], function ($q) use ($filters) {
                return $q->where('a.id', $filters['affiliate_id']);
            })
            ->when($filters['affiliate_type'], function ($q) use ($filters) {
                return $q->where('a.affiliate_type', $filters['affiliate_type']);
            })
            ->whereBetween('m.created_at', [$startDate, $endDate])
            ->groupBy(DB::raw("DATE_TRUNC('month', m.created_at)"), 'a.name')
            ->orderBy('month')
            ->orderBy('new_members', 'desc')
            ->limit(50)
            ->get()
            ->groupBy('affiliate_name')
            ->map(function ($affiliateData, $affiliateName) {
                return [
                    'affiliate_name' => $affiliateName,
                    'data' => $affiliateData->map(function ($item) {
                        return [
                            'month' => Carbon::parse($item->month)->format('M Y'),
                            'new_members' => (int)$item->new_members
                        ];
                    })->toArray()
                ];
            })
            ->values()
            ->toArray();
    }

private function getMemberGrowthTimeline($filters)
{
    $schema = config('database.connections.pgsql.search_path');
    
    // Use date_of_hire directly instead of COALESCE
    $startDate = Carbon::parse(now()->subMonths(11))->startOfMonth();
    $endDate = Carbon::parse(now())->endOfMonth();

    $monthlyData = DB::table($schema . '.members as m')
        ->select(
            DB::raw("DATE_TRUNC('month', m.date_of_hire) as month"), // Use date_of_hire directly
            DB::raw('COUNT(*) as new_members')
        )
        ->when($filters['affiliate_id'], function ($q) use ($filters) {
            return $q->where('m.affiliate_id', $filters['affiliate_id']);
        })
        ->when($filters['member_level'], function ($q) use ($filters) {
            if ($filters['member_level'] === 'Not Specified') {
                return $q->whereNull('m.level');
            }
            return $q->where('m.level', $filters['member_level']);
        })
        ->when($filters['state'], function ($q) use ($filters) {
            return $q->where('m.state', $filters['state']);
        })
        ->whereNotNull('date_of_hire') // Add this to only include members with date_of_hire
        ->whereBetween('date_of_hire', [$startDate, $endDate]) // Use date_of_hire directly
        ->groupBy(DB::raw("DATE_TRUNC('month', m.date_of_hire)"))
        ->orderBy('month')
        ->get()
        ->keyBy(function ($item) {
            return Carbon::parse($item->month)->format('Y-m');
        });

    // Generate all months in range
    $months = [];
    $current = $startDate->copy()->startOfMonth();
    $cumulative = 0;

    while ($current <= $endDate) {
        $monthKey = $current->format('Y-m');
        $newMembers = $monthlyData[$monthKey]->new_members ?? 0;
        $cumulative += $newMembers;

        $months[] = [
            'month' => $current->format('M Y'),
            'date' => $monthKey,
            'new_members' => (int)$newMembers,
            'cumulative_members' => $cumulative
        ];

        $current->addMonth();
    }

    return $months;
}
 private function getHiringTrendsByYear($filters)
    {
        $schema = config('database.connections.pgsql.search_path');
        
        // Only show data for members who actually have date_of_hire
        return DB::table($schema . '.members as m')
            ->select(
                DB::raw("EXTRACT(YEAR FROM date_of_hire) as year"),
                DB::raw('COUNT(*) as hire_count')
            )
            ->when($filters['affiliate_id'], function ($q) use ($filters) {
                return $q->where('m.affiliate_id', $filters['affiliate_id']);
            })
            ->when($filters['member_level'], function ($q) use ($filters) {
                if ($filters['member_level'] === 'Not Specified') {
                    return $q->whereNull('m.level');
                }
                return $q->where('m.level', $filters['member_level']);
            })
            ->whereNotNull('date_of_hire')
            ->groupBy(DB::raw("EXTRACT(YEAR FROM date_of_hire)"))
            ->orderBy('year')
            ->get()
            ->map(function ($item) {
                return [
                    'year' => (int)$item->year,
                    'hire_count' => (int)$item->hire_count,
                    'has_data' => true
                ];
            })
            ->toArray();
    }

 private function getAgeDistribution($filters)
    {
        $schema = config('database.connections.pgsql.search_path');
        
        // Only include members with date_of_birth
        $ageData = DB::table($schema . '.members as m')
            ->select(
                DB::raw("CASE
                    WHEN date_of_birth IS NULL THEN 'Not Specified'
                    WHEN EXTRACT(YEAR FROM AGE(date_of_birth)) < 25 THEN 'Under 25'
                    WHEN EXTRACT(YEAR FROM AGE(date_of_birth)) BETWEEN 25 AND 34 THEN '25-34'
                    WHEN EXTRACT(YEAR FROM AGE(date_of_birth)) BETWEEN 35 AND 44 THEN '35-44'
                    WHEN EXTRACT(YEAR FROM AGE(date_of_birth)) BETWEEN 45 AND 54 THEN '45-54'
                    WHEN EXTRACT(YEAR FROM AGE(date_of_birth)) BETWEEN 55 AND 64 THEN '55-64'
                    ELSE '65+'
                END as age_group"),
                DB::raw('COUNT(*) as count')
            )
            ->when($filters['affiliate_id'], function ($q) use ($filters) {
                return $q->where('m.affiliate_id', $filters['affiliate_id']);
            })
            ->groupBy(DB::raw("CASE
                WHEN date_of_birth IS NULL THEN 'Not Specified'
                WHEN EXTRACT(YEAR FROM AGE(date_of_birth)) < 25 THEN 'Under 25'
                WHEN EXTRACT(YEAR FROM AGE(date_of_birth)) BETWEEN 25 AND 34 THEN '25-34'
                WHEN EXTRACT(YEAR FROM AGE(date_of_birth)) BETWEEN 35 AND 44 THEN '35-44'
                WHEN EXTRACT(YEAR FROM AGE(date_of_birth)) BETWEEN 45 AND 54 THEN '45-54'
                WHEN EXTRACT(YEAR FROM AGE(date_of_birth)) BETWEEN 55 AND 64 THEN '55-64'
                ELSE '65+'
            END"))
            ->orderBy(DB::raw("MIN(EXTRACT(YEAR FROM AGE(date_of_birth)))"))
            ->get();

        $total = $ageData->sum('count');
        
        return $ageData->map(function ($item) use ($total) {
            $percentage = $total > 0 ? round(($item->count / $total) * 100, 1) : 0;
            return [
                'age_group' => $item->age_group,
                'count' => (int)$item->count,
                'percentage' => $percentage
            ];
        })->toArray();
    }


private function getTenureAnalysis($filters)
    {
        $schema = config('database.connections.pgsql.search_path');
        
        // Only include members with date_of_hire
        $tenureData = DB::table($schema . '.members as m')
            ->select(
                DB::raw("CASE
                    WHEN date_of_hire IS NULL THEN 'Not Specified'
                    WHEN EXTRACT(YEAR FROM AGE(date_of_hire)) < 1 THEN 'Less than 1 year'
                    WHEN EXTRACT(YEAR FROM AGE(date_of_hire)) BETWEEN 1 AND 3 THEN '1-3 years'
                    WHEN EXTRACT(YEAR FROM AGE(date_of_hire)) BETWEEN 4 AND 7 THEN '4-7 years'
                    WHEN EXTRACT(YEAR FROM AGE(date_of_hire)) BETWEEN 8 AND 15 THEN '8-15 years'
                    WHEN EXTRACT(YEAR FROM AGE(date_of_hire)) BETWEEN 16 AND 25 THEN '16-25 years'
                    ELSE 'More than 25 years'
                END as tenure_group"),
                DB::raw('COUNT(*) as count')
            )
            ->when($filters['affiliate_id'], function ($q) use ($filters) {
                return $q->where('m.affiliate_id', $filters['affiliate_id']);
            })
            ->groupBy(DB::raw("CASE
                WHEN date_of_hire IS NULL THEN 'Not Specified'
                WHEN EXTRACT(YEAR FROM AGE(date_of_hire)) < 1 THEN 'Less than 1 year'
                WHEN EXTRACT(YEAR FROM AGE(date_of_hire)) BETWEEN 1 AND 3 THEN '1-3 years'
                WHEN EXTRACT(YEAR FROM AGE(date_of_hire)) BETWEEN 4 AND 7 THEN '4-7 years'
                WHEN EXTRACT(YEAR FROM AGE(date_of_hire)) BETWEEN 8 AND 15 THEN '8-15 years'
                WHEN EXTRACT(YEAR FROM AGE(date_of_hire)) BETWEEN 16 AND 25 THEN '16-25 years'
                ELSE 'More than 25 years'
            END"))
            ->orderBy(DB::raw("MIN(EXTRACT(YEAR FROM AGE(date_of_hire)))"))
            ->get();

        return $tenureData->map(function ($item) {
            return [
                'tenure_group' => $item->tenure_group,
                'count' => (int)$item->count,
                'has_data' => $item->tenure_group !== 'Not Specified'
            ];
        })->toArray();
    }


private function getDocumentUploadsStats($filters)
    {
        $schema = config('database.connections.pgsql.search_path');
        
        if (!Schema::hasTable($schema . '.documents')) {
            return [
                'total_documents' => 0,
                'by_category' => [],
                'by_type' => []
            ];
        }

        $totalDocuments = DB::table($schema . '.documents as d')
            ->when($filters['affiliate_id'], function ($q) use ($filters) {
                return $q->where('d.affiliate_id', $filters['affiliate_id']);
            })
            ->count();

        $byCategory = DB::table($schema . '.documents as d')
            ->select(
                DB::raw("COALESCE(d.category, 'Not Specified') as category"),
                DB::raw('COUNT(*) as count')
            )
            ->when($filters['affiliate_id'], function ($q) use ($filters) {
                return $q->where('d.affiliate_id', $filters['affiliate_id']);
            })
            ->groupBy(DB::raw("COALESCE(d.category, 'Not Specified')"))
            ->orderBy('count', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    'category' => $item->category,
                    'count' => (int)$item->count
                ];
            })
            ->toArray();

        $byType = DB::table($schema . '.documents as d')
            ->select(
                DB::raw("COALESCE(d.type, 'Not Specified') as type"),
                DB::raw('COUNT(*) as count')
            )
            ->when($filters['affiliate_id'], function ($q) use ($filters) {
                return $q->where('d.affiliate_id', $filters['affiliate_id']);
            })
            ->groupBy(DB::raw("COALESCE(d.type, 'Not Specified')"))
            ->orderBy('count', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    'type' => $item->type,
                    'count' => (int)$item->count
                ];
            })
            ->toArray();

        return [
            'total_documents' => (int)$totalDocuments,
            'by_category' => $byCategory,
            'by_type' => $byType
        ];
    }


private function getOfficerAssignmentsStats($filters)
    {
        $schema = config('database.connections.pgsql.search_path');
        
        if (!Schema::hasTable($schema . '.affiliate_officers')) {
            return [
                'total_positions' => 0,
                'filled_positions' => 0,
                'vacant_positions' => 0,
                'fill_rate' => 0
            ];
        }

        $stats = DB::table($schema . '.affiliate_officers as ao')
            ->select(
                DB::raw('COUNT(*) as total_positions'),
                DB::raw('SUM(CASE WHEN ao.is_vacant = false THEN 1 ELSE 0 END) as filled_positions'),
                DB::raw('SUM(CASE WHEN ao.is_vacant = true THEN 1 ELSE 0 END) as vacant_positions')
            )
            ->when($filters['affiliate_id'], function ($q) use ($filters) {
                return $q->where('ao.affiliate_id', $filters['affiliate_id']);
            })
            ->first();

        $total = $stats->total_positions ?? 0;
        $filled = $stats->filled_positions ?? 0;
        $vacant = $stats->vacant_positions ?? 0;
        $fillRate = $total > 0 ? round(($filled / $total) * 100, 1) : 0;

        return [
            'total_positions' => (int)$total,
            'filled_positions' => (int)$filled,
            'vacant_positions' => (int)$vacant,
            'fill_rate' => $fillRate
        ];
    }

private function getComplianceStatus($filters)
    {
        $schema = config('database.connections.pgsql.search_path');
        
        // Check for missing critical data
        $totalMembers = DB::table($schema . '.members as m')
            ->when($filters['affiliate_id'], function ($q) use ($filters) {
                return $q->where('m.affiliate_id', $filters['affiliate_id']);
            })
            ->count();

        $missingEmail = DB::table($schema . '.members as m')
            ->where(function ($q) {
                $q->whereNull('work_email')
                  ->orWhere('work_email', '')
                  ->orWhere('work_email', 'like', '%@example.com%');
            })
            ->when($filters['affiliate_id'], function ($q) use ($filters) {
                return $q->where('m.affiliate_id', $filters['affiliate_id']);
            })
            ->count();

        $missingPhone = DB::table($schema . '.members as m')
            ->where(function ($q) {
                $q->whereNull('mobile_phone')
                  ->orWhere('mobile_phone', '')
                  ->orWhere('mobile_phone', 'like', '0000000000%');
            })
            ->when($filters['affiliate_id'], function ($q) use ($filters) {
                return $q->where('m.affiliate_id', $filters['affiliate_id']);
            })
            ->count();

        $missingAddress = DB::table($schema . '.members as m')
            ->where(function ($q) {
                $q->whereNull('address_line1')
                  ->orWhere('address_line1', '');
            })
            ->when($filters['affiliate_id'], function ($q) use ($filters) {
                return $q->where('m.affiliate_id', $filters['affiliate_id']);
            })
            ->count();

        return [
            'total_members' => (int)$totalMembers,
            'missing_data' => [
                'email' => (int)$missingEmail,
                'phone' => (int)$missingPhone,
                'address' => (int)$missingAddress
            ],
            'compliance_rate' => $totalMembers > 0 ? round((($totalMembers - max($missingEmail, $missingPhone, $missingAddress)) / $totalMembers) * 100, 1) : 0
        ];
    }


 private function getDataQualityScore($filters)
    {
        $schema = config('database.connections.pgsql.search_path');
        
        $totalMembers = DB::table($schema . '.members as m')
            ->when($filters['affiliate_id'], function ($q) use ($filters) {
                return $q->where('m.affiliate_id', $filters['affiliate_id']);
            })
            ->count();

        if ($totalMembers === 0) {
            return 0;
        }

        // Calculate completeness for each field (date_of_hire has low weight since it's often missing)
        $fields = [
            'work_email' => [
                'count' => DB::table($schema . '.members as m')
                    ->whereNotNull('work_email')
                    ->where('work_email', '!=', '')
                    ->where('work_email', 'not like', '%@example.com%')
                    ->when($filters['affiliate_id'], function ($q) use ($filters) {
                        return $q->where('m.affiliate_id', $filters['affiliate_id']);
                    })
                    ->count(),
                'weight' => 30  // High importance
            ],
            
            'mobile_phone' => [
                'count' => DB::table($schema . '.members as m')
                    ->whereNotNull('mobile_phone')
                    ->where('mobile_phone', '!=', '')
                    ->when($filters['affiliate_id'], function ($q) use ($filters) {
                        return $q->where('m.affiliate_id', $filters['affiliate_id']);
                    })
                    ->count(),
                'weight' => 25  // High importance
            ],
            
            'address_line1' => [
                'count' => DB::table($schema . '.members as m')
                    ->whereNotNull('address_line1')
                    ->where('address_line1', '!=', '')
                    ->when($filters['affiliate_id'], function ($q) use ($filters) {
                        return $q->where('m.affiliate_id', $filters['affiliate_id']);
                    })
                    ->count(),
                'weight' => 20  // Medium importance
            ],
            
            'date_of_hire' => [
                'count' => DB::table($schema . '.members as m')
                    ->whereNotNull('date_of_hire')
                    ->when($filters['affiliate_id'], function ($q) use ($filters) {
                        return $q->where('m.affiliate_id', $filters['affiliate_id']);
                    })
                    ->count(),
                'weight' => 5   // Low importance (legacy data issue)
            ],

            'date_of_birth' => [
                'count' => DB::table($schema . '.members as m')
                    ->whereNotNull('date_of_birth')
                    ->when($filters['affiliate_id'], function ($q) use ($filters) {
                        return $q->where('m.affiliate_id', $filters['affiliate_id']);
                    })
                    ->count(),
                'weight' => 10  // Medium-low importance
            ],

            'gender' => [
                'count' => DB::table($schema . '.members as m')
                    ->whereNotNull('gender')
                    ->where('gender', '!=', '')
                    ->when($filters['affiliate_id'], function ($q) use ($filters) {
                        return $q->where('m.affiliate_id', $filters['affiliate_id']);
                    })
                    ->count(),
                'weight' => 10  // Medium-low importance
            ]
        ];

        // Calculate weighted average completeness
        $totalWeightedScore = 0;
        $totalWeight = 0;
        
        foreach ($fields as $field => $data) {
            $completeness = ($data['count'] / $totalMembers) * 100;
            $weightedScore = $completeness * ($data['weight'] / 100);
            $totalWeightedScore += $weightedScore;
            $totalWeight += $data['weight'];
        }

        return $totalWeight > 0 ? round(($totalWeightedScore / $totalWeight) * 100, 1) : 0;
    }


    // 6. RESEARCH & GOVERNANCE FUNCTIONS - NO date_of_hire filters
    private function getDocumentCategoriesSplit($filters)
    {
        if (!Schema::hasTable('documents')) {
            return [
                'research' => 0,
                'governance' => 0,
                'other' => 0
            ];
        }

        $categories = DB::table('documents as d')
            ->select(
                DB::raw("CASE 
                    WHEN d.category_group = 'research' THEN 'Research'
                    WHEN d.category_group = 'governance' THEN 'Governance'
                    ELSE 'Other'
                END as category_type"),
                DB::raw('COUNT(*) as count')
            )
            ->when($filters['affiliate_id'], function ($q) use ($filters) {
                return $q->where('d.affiliate_id', $filters['affiliate_id']);
            })
            ->groupBy(DB::raw("CASE 
                WHEN d.category_group = 'research' THEN 'Research'
                WHEN d.category_group = 'governance' THEN 'Governance'
                ELSE 'Other'
            END"))
            ->get();

        $result = [
            'research' => 0,
            'governance' => 0,
            'other' => 0
        ];

        foreach ($categories as $category) {
            $result[strtolower($category->category_type)] = (int)$category->count;
        }

        return $result;
    }

    private function getContractArbitrationStats($filters)
    {
        $contracts = Schema::hasTable('contracts') ? DB::table('contracts')->count() : 0;
        $arbitrations = Schema::hasTable('arbitrations') ? DB::table('arbitrations')->count() : 0;

        return [
            'total_contracts' => (int)$contracts,
            'total_arbitrations' => (int)$arbitrations,
            'files_status' => [
                'uploaded' => $contracts + $arbitrations,
                'missing' => 0
            ]
        ];
    }

    private function getComplianceDashboard($filters)
    {
        // Check for missing EINs in affiliates
        $affiliatesWithoutEIN = Schema::hasTable('affiliates') 
            ? DB::table('affiliates as a')
                ->where(function ($q) {
                    $q->whereNull('ein')
                      ->orWhere('ein', '');
                })
                ->when($filters['affiliate_id'], function ($q) use ($filters) {
                    return $q->where('a.id', $filters['affiliate_id']);
                })
                ->count()
            : 0;

        $totalAffiliates = Schema::hasTable('affiliates') 
            ? DB::table('affiliates')
                ->when($filters['affiliate_id'], function ($q) use ($filters) {
                    return $q->where('id', $filters['affiliate_id']);
                })
                ->count()
            : 0;

        return [
            'missing_eins' => (int)$affiliatesWithoutEIN,
            'total_affiliates' => (int)$totalAffiliates,
            'compliance_rate' => $totalAffiliates > 0 
                ? round((($totalAffiliates - $affiliatesWithoutEIN) / $totalAffiliates) * 100, 1)
                : 0
        ];
    }

private function getDataQualityDetails($filters)
    {
        $totalMembers = DB::table('members as m')
            ->when($filters['affiliate_id'], function ($q) use ($filters) {
                return $q->where('m.affiliate_id', $filters['affiliate_id']);
            })
            ->count();

        // Adjusted weights based on importance and data availability
        $fields = [
            'work_email' => [
                'display' => 'Work Email', 
                'weight' => 30, 
                'importance' => 'High',
                'is_date' => false
            ],
            'mobile_phone' => [
                'display' => 'Mobile Phone', 
                'weight' => 25, 
                'importance' => 'High',
                'is_date' => false
            ],
            'address_line1' => [
                'display' => 'Address', 
                'weight' => 20, 
                'importance' => 'Medium',
                'is_date' => false
            ],
            'date_of_hire' => [
                'display' => 'Date of Hire', 
                'weight' => 5, 
                'importance' => 'Low (Legacy)',
                'is_date' => true
            ],
            'date_of_birth' => [
                'display' => 'Date of Birth', 
                'weight' => 10, 
                'importance' => 'Medium',
                'is_date' => true
            ],
            'gender' => [
                'display' => 'Gender', 
                'weight' => 10, 
                'importance' => 'Medium',
                'is_date' => false
            ]
        ];

        $details = [];
        $totalWeight = 0;
        $totalScore = 0;

        foreach ($fields as $field => $config) {
            // Handle date fields differently (they can't be compared with empty string)
            if ($config['is_date']) {
                // For date fields, just check if they're not null
                $completeCount = DB::table('members as m')
                    ->whereNotNull($field)
                    ->when($filters['affiliate_id'], function ($q) use ($filters) {
                        return $q->where('m.affiliate_id', $filters['affiliate_id']);
                    })
                    ->count();
            } else {
                // For non-date fields, check not null and not empty
                $completeCount = DB::table('members as m')
                    ->whereNotNull($field)
                    ->where($field, '!=', '')
                    ->when($filters['affiliate_id'], function ($q) use ($filters) {
                        return $q->where('m.affiliate_id', $filters['affiliate_id']);
                    })
                    ->count();
            }

            $completeness = $totalMembers > 0 ? round(($completeCount / $totalMembers) * 100, 1) : 0;
            $fieldScore = $completeness * ($config['weight'] / 100);
            
            $details[] = [
                'field' => $config['display'],
                'completeness' => $completeness,
                'weight' => $config['weight'],
                'importance' => $config['importance'],
                'score' => round($fieldScore, 1),
                'complete_count' => (int)$completeCount,
                'is_date_field' => $config['is_date']
            ];

            $totalWeight += $config['weight'];
            $totalScore += $fieldScore;
        }

        // Normalize to 100%
        $overallScore = $totalWeight > 0 ? round(($totalScore / $totalWeight) * 100, 1) : 0;

        return [
            'overall_score' => $overallScore,
            'field_details' => $details,
            'total_members' => (int)$totalMembers,
            'note' => 'Date of Hire has low weight due to legacy data limitations'
        ];
    }

    // ==================== HELPER FUNCTIONS ====================

    private function getMemberTrendComparison($filters)
    {
        $currentMembers = DB::table('members as m')
            ->when($filters['affiliate_id'], function ($q) use ($filters) {
                return $q->where('m.affiliate_id', $filters['affiliate_id']);
            })
            ->count();

        $previousMembers = DB::table('members as m')
            ->where('date_of_hire', '<', now()->subDays(30))
            ->when($filters['affiliate_id'], function ($q) use ($filters) {
                return $q->where('m.affiliate_id', $filters['affiliate_id']);
            })
            ->count();

        if ($previousMembers === 0) {
            return $currentMembers > 0 ? 100 : 0;
        }

        return round((($currentMembers - $previousMembers) / $previousMembers) * 100, 1);
    }

    private function getAffiliateTrendComparison($filters)
    {
        $currentAffiliates = DB::table('affiliates')
            ->when($filters['affiliate_id'], function ($q) use ($filters) {
                return $q->where('id', $filters['affiliate_id']);
            })
            ->when($filters['affiliate_type'], function ($q) use ($filters) {
                return $q->where('affiliate_type', $filters['affiliate_type']);
            })
            ->count();

        $previousAffiliates = DB::table('affiliates')
            ->where('created_at', '<', now()->subDays(30))
            ->when($filters['affiliate_id'], function ($q) use ($filters) {
                return $q->where('id', $filters['affiliate_id']);
            })
            ->when($filters['affiliate_type'], function ($q) use ($filters) {
                return $q->where('affiliate_type', $filters['affiliate_type']);
            })
            ->count();

        if ($previousAffiliates === 0) {
            return $currentAffiliates > 0 ? 100 : 0;
        }

        return round((($currentAffiliates - $previousAffiliates) / $previousAffiliates) * 100, 1);
    }

    private function calculateEngagementRate($filters)
    {
        $totalMembers = DB::table('members as m')
            ->when($filters['affiliate_id'], function ($q) use ($filters) {
                return $q->where('m.affiliate_id', $filters['affiliate_id']);
            })
            ->count();

        $activeMembers = DB::table('members as m')
            ->where('status', 'Active')
            ->when($filters['affiliate_id'], function ($q) use ($filters) {
                return $q->where('m.affiliate_id', $filters['affiliate_id']);
            })
            ->count();

        return $totalMembers > 0 ? round(($activeMembers / $totalMembers) * 100, 1) : 0;
    }

    private function getExecutiveMetricsForPeriod($filters, $startDate, $endDate)
    {
        // Helper to get metrics for a specific time period
        try {
            $totalMembers = DB::table('members as m')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->when($filters['affiliate_id'], function ($q) use ($filters) {
                    return $q->where('m.affiliate_id', $filters['affiliate_id']);
                })
                ->count();

            $activeCount = DB::table('members as m')
                ->where('status', 'Active')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->when($filters['affiliate_id'], function ($q) use ($filters) {
                    return $q->where('m.affiliate_id', $filters['affiliate_id']);
                })
                ->count();

            $newMembers = DB::table('members as m')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->when($filters['affiliate_id'], function ($q) use ($filters) {
                    return $q->where('m.affiliate_id', $filters['affiliate_id']);
                })
                ->count();

            return [
                'total_members' => (int)$totalMembers,
                'active_inactive_ratio' => [
                    'active_count' => (int)$activeCount
                ],
                'new_members_last_30_days' => (int)$newMembers
            ];
        } catch (\Exception $e) {
            return [
                'total_members' => 0,
                'active_inactive_ratio' => ['active_count' => 0],
                'new_members_last_30_days' => 0
            ];
        }
    }

    private function calculateTrend($current, $previous)
    {
        if ($previous === 0) {
            return $current > 0 ? 100 : 0;
        }
        
        $change = (($current - $previous) / $previous) * 100;
        return round($change, 1);
    }

    private function errorResponse($e)
    {
        return response()->json([
            'success' => false,
            'message' => 'Failed to load dashboard data',
            'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
        ], 500);
    }

    private function getDefaultMetrics()
    {
        return [
            'total_members' => 0,
            'active_inactive_ratio' => [
                'active' => 0,
                'inactive' => 0,
                'active_count' => 0,
                'inactive_count' => 0
            ],
            'total_affiliates' => 0,
            'new_members_last_30_days' => 0,
            'pending_actions' => 0
        ];
    }

    // ==================== KEEP EXISTING METHODS FOR BACKWARD COMPATIBILITY ====================

    private function getMembershipAnalytics($filters)
    {
        try {
            $schema = config('database.connections.pgsql.search_path');
            
            // Use created_at instead of date_of_hire for all queries
            $levelDistribution = DB::table($schema . '.members as m')
                ->select(
                    DB::raw("COALESCE(m.level, 'Not Specified') as level"),
                    DB::raw('COUNT(*) as count')
                )
                ->when($filters['affiliate_id'], function ($q) use ($filters) {
                    return $q->where('m.affiliate_id', $filters['affiliate_id']);
                })
                ->groupBy(DB::raw("COALESCE(m.level, 'Not Specified')"))
                ->orderBy('count', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($item) {
                    return [
                        'level' => $item->level,
                        'count' => (int)$item->count
                    ];
                })
                ->toArray();

            $employmentDistribution = DB::table($schema . '.members as m')
                ->select(
                    DB::raw("COALESCE(m.employment_status, 'Not Specified') as employment_status"),
                    DB::raw('COUNT(*) as count')
                )
                ->when($filters['affiliate_id'], function ($q) use ($filters) {
                    return $q->where('m.affiliate_id', $filters['affiliate_id']);
                })
                ->groupBy(DB::raw("COALESCE(m.employment_status, 'Not Specified')"))
                ->orderBy('count', 'desc')
                ->limit(value: 5)
                ->get()
                ->map(function ($item) {
                    return [
                        'employment_status' => $item->employment_status,
                        'count' => (int)$item->count
                    ];
                })
                ->toArray();

            $statusDistribution = DB::table($schema . '.members as m')
                ->select(
                    DB::raw("COALESCE(m.status, 'Not Specified') as status"),
                    DB::raw('COUNT(*) as count')
                )
                ->when($filters['affiliate_id'], function ($q) use ($filters) {
                    return $q->where('m.affiliate_id', $filters['affiliate_id']);
                })
                ->groupBy(DB::raw("COALESCE(m.status, 'Not Specified')"))
                ->orderBy('count', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($item) {
                    return [
                        'status' => $item->status,
                        'count' => (int)$item->count
                    ];
                })
                ->toArray();

            // Use getMemberGrowthTimeline which now uses created_at
            $growthTrend = $this->getMemberGrowthTimeline($filters);

            $topAffiliates = $this->getTopAffiliatesByMembers($filters, 5);

            return [
                'growth_trend' => $growthTrend,
                'level_distribution' => $levelDistribution,
                'employment_distribution' => $employmentDistribution,
                'status_distribution' => $statusDistribution,
                'top_affiliates' => $topAffiliates
            ];
        } catch (\Exception $e) {
            Log::error('Error in getMembershipAnalytics: ' . $e->getMessage());
            return [
                'growth_trend' => [],
                'level_distribution' => [],
                'employment_distribution' => [],
                'status_distribution' => [],
                'top_affiliates' => []
            ];
        }
    }



private function getDirectoryData($affiliateId = null)
{
    try {
        $schema = config('database.connections.pgsql.search_path');
        
        // Get affiliate officers with profile photos
        $affiliateOfficers = [];

        // If affiliate_officers table exists, get officers from there
        if (Schema::hasTable($schema . '.affiliate_officers')) {
            $affiliateOfficers = DB::table($schema . '.affiliate_officers as ao')
                ->leftJoin($schema . '.members as m', 'ao.member_id', '=', 'm.id')
                ->leftJoin($schema . '.affiliates as a', 'ao.affiliate_id', '=', 'a.id')
                ->leftJoin($schema . '.officer_positions as op', 'ao.position_id', '=', 'op.id')
                ->select(
                    'ao.id as officer_id',
                    'm.id as member_id',
                    'm.public_uid',
                    DB::raw("CONCAT(m.first_name, ' ', m.last_name) as member_name"),
                    'op.name as position_name',
                    'm.mobile_phone',
                    'm.work_phone',
                    'm.work_email',
                    'm.home_email',
                    'm.profile_photo_url',
                    'm.address_line1',
                    'm.city',
                    'm.state',
                    'm.zip_code',
                    'a.name as affiliate_name',
                    'a.logo_url as affiliate_logo_url',
                    'ao.is_vacant',
                    'ao.member_id'
                )
                ->when($affiliateId, function ($query) use ($affiliateId) {
                    return $query->where('ao.affiliate_id', $affiliateId);
                })
                ->where('ao.is_primary', true)
                ->where(function ($query) {
                    $query->whereNull('ao.end_date')
                        ->orWhere('ao.end_date', '>=', now());
                })
                ->where('ao.is_vacant', false)
                ->whereNotNull('ao.member_id')
                ->orderBy('a.name')
                ->orderBy('op.display_order')
                ->get()
                ->map(function ($officer) {
                    // Generate profile photo URL
                    $profilePhotoUrl = null;
                    if ($officer->profile_photo_url) {
                        try {
                            $profilePhotoUrl = Storage::disk('supabase')->temporaryUrl(
                                $officer->profile_photo_url,
                                now()->addMinutes(60)
                            );
                        } catch (\Exception $e) {
                            Log::error('Error generating officer profile photo URL: ' . $e->getMessage());
                            $profilePhotoUrl = null;
                        }
                    }

                    // Generate affiliate logo URL
                    $affiliateLogoUrl = null;
                    if ($officer->affiliate_logo_url) {
                        try {
                            $affiliateLogoUrl = Storage::disk('supabase')->temporaryUrl(
                                $officer->affiliate_logo_url,
                                now()->addMinutes(60)
                            );
                        } catch (\Exception $e) {
                            Log::error('Error generating affiliate logo URL: ' . $e->getMessage());
                            $affiliateLogoUrl = null;
                        }
                    }

                    return [
                        'officer_id' => $officer->officer_id,
                        'member_id' => $officer->member_id,
                        'member_name' => $officer->member_name,
                        'position' => $officer->position_name,
                        'mobile_phone' => $officer->mobile_phone,
                        'work_phone' => $officer->work_phone,
                        'work_email' => $officer->work_email,
                        'home_email' => $officer->home_email,
                        'address_line1' => $officer->address_line1,
                        'city' => $officer->city,
                        'state' => $officer->state,
                        'zip_code' => $officer->zip_code,
                        'profile_photo_url' => $profilePhotoUrl,
                        'affiliate_name' => $officer->affiliate_name,
                        'affiliate_logo_url' => $affiliateLogoUrl
                    ];
                })
                ->toArray();
        }

        // Get national officers using the fixed method
        $nationalOfficers = $this->getOrganizationOfficers();

        // Get stats about national officers
        $nationalOfficerStats = [
            'total' => count($nationalOfficers),
            'national_admins' => count(array_filter($nationalOfficers, function($officer) {
                return $officer['role_id'] == 1 || (isset($officer['is_national_admin']) && $officer['is_national_admin']);
            })),
            'executive_committee' => count(array_filter($nationalOfficers, function($officer) {
                return $officer['role_id'] == 2 || (isset($officer['is_executive_committee']) && $officer['is_executive_committee']);
            })),
            'research_committee' => count(array_filter($nationalOfficers, function($officer) {
                return $officer['role_id'] == 3 || (isset($officer['is_research_committee']) && $officer['is_research_committee']);
            })),
        ];

        return [
            'affiliate_officers' => $affiliateOfficers,
            'national_officers' => $nationalOfficers,
            'total_officers' => count($affiliateOfficers) + count($nationalOfficers),
            'national_officer_stats' => $nationalOfficerStats
        ];
    } catch (\Exception $e) {
        Log::error('Error in getDirectoryData: ' . $e->getMessage());
        return [
            'affiliate_officers' => [],
            'national_officers' => [],
            'total_officers' => 0,
            'national_officer_stats' => [
                'total' => 0,
                'national_admins' => 0,
                'executive_committee' => 0,
                'research_committee' => 0
            ]
        ];
    }
}

public function getFilterOptions()
    {
        try {
            $schema = config('database.connections.pgsql.search_path');
            
            $affiliates = DB::table($schema . '.affiliates')
                ->select('id', 'name', 'affiliate_type', 'state')
                ->orderBy('name')
                ->limit(100)
                ->get()
                ->toArray();

            $memberLevels = ['Associate', 'Professional', 'Retired', 'Not Specified'];
            $affiliateTypes = ['Associate', 'Professional', 'Wall-to-Wall'];
            
            // Get states with count
            $states = DB::table($schema . '.members')
                ->select(DB::raw("
                    COALESCE(state, 'Not Specified') as state,
                    COUNT(*) as count
                "))
                ->groupBy(DB::raw("COALESCE(state, 'Not Specified')"))
                ->orderBy('state')
                ->get()
                ->map(function($item) {
                    return [
                        'value' => $item->state,
                        'label' => $item->state . ' (' . $item->count . ')'
                    ];
                })
                ->toArray();
                
            $cbcRegions = DB::table($schema . '.affiliates')
                ->select(DB::raw("
                    COALESCE(cbc_region, 'Not Specified') as cbc_region,
                    COUNT(*) as count
                "))
                ->groupBy(DB::raw("COALESCE(cbc_region, 'Not Specified')"))
                ->orderBy('cbc_region')
                ->get()
                ->map(function($item) {
                    return [
                        'value' => $item->cbc_region,
                        'label' => $item->cbc_region . ' (' . $item->count . ')'
                    ];
                })
                ->toArray();
                
            $nsoRegions = DB::table($schema . '.affiliates')
                ->select(DB::raw("
                    COALESCE(org_region, 'Not Specified') as org_region,
                    COUNT(*) as count
                "))
                ->groupBy(DB::raw("COALESCE(org_region, 'Not Specified')"))
                ->orderBy('org_region')
                ->get()
                ->map(function($item) {
                    return [
                        'value' => $item->org_region,
                        'label' => $item->org_region . ' (' . $item->count . ')'
                    ];
                })
                ->toArray();

            return response()->json([
                'success' => true,
                'data' => [
                    'affiliates' => $affiliates,
                    'member_levels' => $memberLevels,
                    'states' => $states,
                    'affiliate_types' => array_map(function($type) {
                        return ['value' => $type, 'label' => $type];
                    }, $affiliateTypes),
                    'cbc_regions' => $cbcRegions,
                    'org_regions' => $nsoRegions,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching filter options: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch filter options'
            ], 500);
        }
    }

private function getOrganizationOfficers()
{
    try {
        Log::info('=== SIMPLIFIED getOrganizationOfficers() ===');
        
        // Direct DB query - no Eloquent models
        $nationalOfficers = DB::select("
            SELECT 
                m.id as member_id,
                m.public_uid,
                CONCAT(m.first_name, ' ', m.last_name) as member_name,
                nr.name as position,
                nr.description as role_description,
                m.mobile_phone,
                m.work_phone,
                m.work_email,
                m.home_email,
                m.profile_photo_url,
                m.affiliate_id,
                mnr.role_id,
                mnr.created_at as role_assigned_date
            FROM member_national_roles mnr
            JOIN national_roles nr ON mnr.role_id = nr.id
            JOIN members m ON mnr.member_id = m.id
            WHERE m.status = 'Active'
            ORDER BY nr.name, m.last_name
        ");
        
        Log::info('Simplified query found ' . count($nationalOfficers) . ' national officers');
        
        if (empty($nationalOfficers)) {
            Log::warning('No national officers found with simplified query');
            return [];
        }
        
        // Convert stdClass objects to arrays and add profile photo URLs
        $result = [];
        foreach ($nationalOfficers as $officer) {
            $profilePhotoUrl = null;
            if (!empty($officer->profile_photo_url)) {
                try {
                    $profilePhotoUrl = Storage::disk('supabase')->temporaryUrl(
                        $officer->profile_photo_url,
                        now()->addMinutes(10)
                    );
                } catch (\Exception $e) {
                    Log::error('Error generating profile photo URL for member ' . $officer->member_id . ': ' . $e->getMessage());
                }
            }
            
            $result[] = [
                'position' => $officer->position,
                'member_name' => $officer->member_name,
                'mobile_phone' => $officer->mobile_phone,
                'work_phone' => $officer->work_phone,
                'work_email' => $officer->work_email,
                'home_email' => $officer->home_email,
                'profile_photo_url' => $profilePhotoUrl,
                'member_id' => $officer->member_id,
                'public_uid' => $officer->public_uid,
                'affiliate_id' => $officer->affiliate_id,
                'role_id' => $officer->role_id,
                'role_description' => $officer->role_description,
                'role_assigned_date' => $officer->role_assigned_date,
                'is_national_administrator' => $officer->role_id == 1,
                'is_executive_committee' => $officer->role_id == 2,
                'is_research_committee' => $officer->role_id == 3,
            ];
        }
        
        // Group by member_id if needed (for members with multiple roles)
        $groupedResult = [];
        foreach ($result as $officer) {
            $memberId = $officer['member_id'];
            if (!isset($groupedResult[$memberId])) {
                $groupedResult[$memberId] = $officer;
            } else {
                // Combine multiple roles
                $groupedResult[$memberId]['position'] .= ', ' . $officer['position'];
                $groupedResult[$memberId]['role_id'] = [$groupedResult[$memberId]['role_id'], $officer['role_id']];
                $groupedResult[$memberId]['has_multiple_roles'] = true;
            }
        }
        
        Log::info('Returning ' . count($groupedResult) . ' unique national officers');
        
        return array_values($groupedResult);
        
    } catch (\Exception $e) {
        Log::error('Error in simplified getOrganizationOfficers(): ' . $e->getMessage());
        Log::error('Stack trace: ' . $e->getTraceAsString());
        return [];
    }
}
    private function getMemberSearchResults($searchTerm, $limit = 10, $affiliateId = null, $memberLevel = null, $status = null)
    {
        try {
            if (empty($searchTerm)) {
                return [];
            }

            return DB::table('members as m')
                ->select(
                    'm.id',
                    DB::raw("CONCAT(m.first_name, ' ', m.last_name) as name"),
                    'm.member_id',
                    'm.level',
                    'm.status',
                    'm.work_email',
                    'm.work_phone',
                    'm.profile_photo_url',
                    'm.address_line1',
                    'm.city',
                    'm.state',
                    'm.zip_code',
                    'a.name as affiliate_name',
                    'a.logo_url as affiliate_logo_url'
                )
                ->leftJoin('affiliates as a', 'm.affiliate_id', '=', 'a.id')
                ->where(function ($query) use ($searchTerm) {
                    $query->where('m.first_name', 'ilike', "%{$searchTerm}%")
                        ->orWhere('m.last_name', 'ilike', "%{$searchTerm}%")
                        ->orWhere('m.member_id', 'ilike', "%{$searchTerm}%")
                        ->orWhere('m.work_email', 'ilike', "%{$searchTerm}%")
                        ->orWhere('a.name', 'ilike', "%{$searchTerm}%");
                })
                ->when($affiliateId, function ($query) use ($affiliateId) {
                    return $query->where('m.affiliate_id', $affiliateId);
                })
                ->when($memberLevel, function ($query) use ($memberLevel) {
                    if ($memberLevel === 'Not Specified') {
                        return $query->whereNull('m.level');
                    }
                    return $query->where('m.level', $memberLevel);
                })
                ->when($status, function ($query) use ($status) {
                    return $query->where('m.status', $status);
                })
                ->orderBy('m.last_name')
                ->limit($limit)
                ->get()
                ->map(function ($member) {
                    $profilePhotoUrl = null;
                    if ($member->profile_photo_url) {
                        try {
                            $profilePhotoUrl = Storage::disk('supabase')->temporaryUrl(
                                $member->profile_photo_url,
                                now()->addMinutes(60)
                            );
                        } catch (\Exception $e) {
                            Log::error('Error generating profile photo URL: ' . $e->getMessage());
                            $profilePhotoUrl = null;
                        }
                    }

                    $affiliateLogoUrl = null;
                    if ($member->affiliate_logo_url) {
                        try {
                            $affiliateLogoUrl = Storage::disk('supabase')->temporaryUrl(
                                $member->affiliate_logo_url,
                                now()->addMinutes(60)
                            );
                        } catch (\Exception $e) {
                            Log::error('Error generating affiliate logo URL: ' . $e->getMessage());
                            $affiliateLogoUrl = null;
                        }
                    }

                    return [
                        'id' => $member->id,
                        'name' => $member->name,
                        'member_id' => $member->member_id,
                        'level' => $member->level,
                        'status' => $member->status,
                        'work_email' => $member->work_email,
                        'work_phone' => $member->work_phone,
                        'address_line1' => $member->address_line1,
                        'city' => $member->city,
                        'state' => $member->state,
                        'zip_code' => $member->zip_code,
                        'profile_photo_url' => $profilePhotoUrl,
                        'affiliate_name' => $member->affiliate_name,
                        'affiliate_logo_url' => $affiliateLogoUrl
                    ];
                })
                ->toArray();
        } catch (\Exception $e) {
            Log::error('Error in getMemberSearchResults: ' . $e->getMessage());
            return [];
        }
    }

public function universalSearch(Request $request)
{
    try {
        $searchTerm = $request->get('search_term', '');
        $limit = $request->get('limit', 10);
        $searchType = $request->get('search_type', 'all'); // 'all', 'members', 'documents', 'affiliates', 'links', 'national_info'
        $affiliateId = $request->get('affiliate_id');
        
        if (empty($searchTerm)) {
            return response()->json([
                'success' => true,
                'data' => [],
                'message' => 'Please provide a search term',
                'total_results' => 0
            ]);
        }
        
        $results = [];
        $totalResults = 0;
        
        switch ($searchType) {
            case 'members':
                $memberResults = $this->searchMembersOnly($searchTerm, $limit, $affiliateId);
                $results = $memberResults;
                $totalResults = count($memberResults);
                $message = 'Member search completed successfully';
                break;
                
            case 'documents':
                $documentResults = $this->searchDocumentsOnly($searchTerm, $limit, $affiliateId);
                $results = $documentResults;
                $totalResults = count($documentResults);
                $message = 'Document search completed successfully';
                break;
                
            case 'affiliates':
                $affiliateResults = $this->searchAffiliatesOnly($searchTerm, $limit, $affiliateId);
                $results = $affiliateResults;
                $totalResults = count($affiliateResults);
                $message = 'Affiliate search completed successfully';
                break;
                
            case 'links':
                $linkResults = $this->searchLinksOnly($searchTerm, $limit, $affiliateId);
                $results = $linkResults;
                $totalResults = count($linkResults);
                $message = 'Links search completed successfully';
                break;
                
            case 'national_info':
                $nationalInfoResults = $this->searchOrganizationInformationOnly($searchTerm, $limit);
                $results = $nationalInfoResults;
                $totalResults = count($nationalInfoResults);
                $message = 'Organization information search completed successfully';
                break;
                
            case 'all':
            default:
                $allResults = $this->searchAllTypes($searchTerm, $limit, $affiliateId);
                $results = $allResults['results'];
                $totalResults = $allResults['total'];
                $message = 'Universal search completed successfully';
                break;
        }
        
        return response()->json([
            'success' => true,
            'data' => $results,
            'message' => $message,
            'total_results' => $totalResults,
            'search_type' => $searchType,
            'search_term' => $searchTerm
        ]);
        
    } catch (\Exception $e) {
        Log::error('Universal search error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        return response()->json([
            'success' => false,
            'message' => 'Failed to perform search',
            'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
        ], 500);
    }
}

/**
 * Search all types and combine results
 */
private function searchAllTypes($searchTerm, $limit, $affiliateId = null)
{
    // Search each type with distributed limits
    $memberLimit = ceil($limit * 0.35); // 35% for members
    $documentLimit = ceil($limit * 0.25); // 25% for documents
    $affiliateLimit = ceil($limit * 0.15); // 15% for affiliates
    $linkLimit = ceil($limit * 0.15); // 15% for links
    $nationalInfoLimit = ceil($limit * 0.10); // 10% for national information
    
    $members = $this->searchMembersOnly($searchTerm, $memberLimit, $affiliateId);
    $documents = $this->searchDocumentsOnly($searchTerm, $documentLimit, $affiliateId);
    $affiliates = $this->searchAffiliatesOnly($searchTerm, $affiliateLimit, $affiliateId);
    $links = $this->searchLinksOnly($searchTerm, $linkLimit, $affiliateId);
    $nationalInfo = $this->searchOrganizationInformationOnly($searchTerm, $nationalInfoLimit);
    
    // Combine and sort by relevance
    $combinedResults = [];
    
    // Add members with type and score
    foreach ($members as $member) {
        $combinedResults[] = [
            'type' => 'member',
            'score' => $this->calculateSearchScore($searchTerm, $member['name'] . ' ' . $member['member_id']),
            'data' => $member
        ];
    }
    
    // Add documents with type and score
    foreach ($documents as $document) {
        $combinedResults[] = [
            'type' => 'document',
            'score' => $this->calculateSearchScore($searchTerm, $document['title'] . ' ' . $document['description']),
            'data' => $document
        ];
    }
    
    // Add affiliates with type and score
    foreach ($affiliates as $affiliate) {
        $combinedResults[] = [
            'type' => 'affiliate',
            'score' => $this->calculateSearchScore($searchTerm, $affiliate['name'] . ' ' . $affiliate['affiliate_type']),
            'data' => $affiliate
        ];
    }
    
    // Add links with type and score
    foreach ($links as $link) {
        $combinedResults[] = [
            'type' => 'link',
            'score' => $this->calculateSearchScore($searchTerm, $link['title'] . ' ' . $link['description'] . ' ' . $link['url']),
            'data' => $link
        ];
    }
    
    // Add national information with type and score
    foreach ($nationalInfo as $info) {
        $combinedResults[] = [
            'type' => 'national_information',
            'score' => $this->calculateSearchScore($searchTerm, $info['title'] . ' ' . $info['content'] . ' ' . $info['category']),
            'data' => $info
        ];
    }
    
    // Sort by score (highest first)
    usort($combinedResults, function($a, $b) {
        return $b['score'] <=> $a['score'];
    });
    
    // Take only the limit
    $combinedResults = array_slice($combinedResults, 0, $limit);
    
    return [
        'results' => $combinedResults,
        'total' => count($members) + count($documents) + count($affiliates) + count($links) + count($nationalInfo),
        'breakdown' => [
            'members' => count($members),
            'documents' => count($documents),
            'affiliates' => count($affiliates),
            'links' => count($links),
            'national_information' => count($nationalInfo)
        ]
    ];
}

/**
 * Search links only
 */
private function searchLinksOnly($searchTerm, $limit = 10, $affiliateId = null)
{
    try {
        if (empty($searchTerm)) {
            return [];
        }
        
        if (!Schema::hasTable('links')) {
            return [];
        }
        
        return DB::table('links as l')
            ->select(
                'l.id',
                'l.title',
                'l.url',
                'l.description',
                'l.category',
                'l.display_order',
                'l.is_active',
                'l.is_public',
                'l.created_at',
                'l.updated_at',
                'a.name as affiliate_name',
                'a.logo_url as affiliate_logo_url',
                DB::raw("'link' as result_type")
            )
            ->leftJoin('affiliates as a', 'l.affiliate_id', '=', 'a.id')
            ->where(function ($query) use ($searchTerm) {
                $query->where('l.title', 'ilike', "%{$searchTerm}%")
                    ->orWhere('l.description', 'ilike', "%{$searchTerm}%")
                    ->orWhere('l.url', 'ilike', "%{$searchTerm}%")
                    ->orWhere('l.category', 'ilike', "%{$searchTerm}%")
                    ->orWhere('a.name', 'ilike', "%{$searchTerm}%");
            })
            ->when($affiliateId, function ($query) use ($affiliateId) {
                return $query->where('l.affiliate_id', $affiliateId);
            })
            ->where('l.is_active', true)
            ->orderByRaw("
                CASE 
                    WHEN l.title ILIKE ? THEN 1
                    WHEN l.description ILIKE ? THEN 2
                    WHEN l.url ILIKE ? THEN 3
                    ELSE 4
                END
            ", ["%{$searchTerm}%", "%{$searchTerm}%", "%{$searchTerm}%"])
            ->orderBy('l.display_order', 'asc')
            ->orderBy('l.created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($item) {
                // Generate affiliate logo URL
                $affiliateLogoUrl = null;
                if ($item->affiliate_logo_url) {
                    try {
                        $affiliateLogoUrl = Storage::disk('supabase')->temporaryUrl(
                            $item->affiliate_logo_url,
                            now()->addMinutes(60)
                        );
                    } catch (\Exception $e) {
                        Log::error('Error generating affiliate logo URL for link: ' . $e->getMessage());
                        $affiliateLogoUrl = null;
                    }
                }

                return [
                    'id' => $item->id,
                    'type' => 'link',
                    'title' => $item->title,
                    'url' => $item->url,
                    'description' => $item->description,
                    'category' => $item->category,
                    'display_order' => (int)$item->display_order,
                    'is_active' => (bool)$item->is_active,
                    'is_public' => (bool)$item->is_public,
                    'created_at' => $item->created_at ? Carbon::parse($item->created_at)->format('Y-m-d H:i:s') : null,
                    'updated_at' => $item->updated_at ? Carbon::parse($item->updated_at)->format('Y-m-d H:i:s') : null,
                    'affiliate_name' => $item->affiliate_name,
                    'affiliate_logo_url' => $affiliateLogoUrl,
                    'result_type' => $item->result_type
                ];
            })
            ->toArray();
    } catch (\Exception $e) {
        Log::error('Error in searchLinksOnly: ' . $e->getMessage());
        return [];
    }
}

/**
 * Search national information only
 */
private function searchOrganizationInformationOnly($searchTerm, $limit = 10)
{
    try {
        if (empty($searchTerm)) {
            return [];
        }
        
        if (!Schema::hasTable('national_information')) {
            return [];
        }
        
        return DB::table('national_information as ni')
            ->select(
                'ni.id',
                'ni.type',
                'ni.title',
                'ni.content',
                'ni.category',
                'ni.author',
                'ni.status',
                'ni.published_at',
                'ni.created_at',
                'ni.updated_at',
                DB::raw("(
                    SELECT COUNT(*) 
                    FROM national_information_attachments nia 
                    WHERE nia.national_info_id = ni.id
                ) as attachment_count"),
                DB::raw("'national_information' as result_type")
            )
            ->where(function ($query) use ($searchTerm) {
                $query->where('ni.title', 'ilike', "%{$searchTerm}%")
                    ->orWhere('ni.content', 'ilike', "%{$searchTerm}%")
                    ->orWhere('ni.category', 'ilike', "%{$searchTerm}%")
                    ->orWhere('ni.author', 'ilike', "%{$searchTerm}%")
                    ->orWhere('ni.type', 'ilike', "%{$searchTerm}%");
            })
            ->where('ni.status', 'published') // Only show published items
            ->orderByRaw("
                CASE 
                    WHEN ni.title ILIKE ? THEN 1
                    WHEN ni.content ILIKE ? THEN 2
                    WHEN ni.category ILIKE ? THEN 3
                    ELSE 4
                END
            ", ["%{$searchTerm}%", "%{$searchTerm}%", "%{$searchTerm}%"])
            ->orderBy('ni.published_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($item) {
                // Get attachment details if any
                $attachments = [];
                if ($item->attachment_count > 0) {
                    $attachmentRecords = DB::table('national_information_attachments')
                        ->where('national_info_id', $item->id)
                        ->get()
                        ->map(function ($attachment) {
                            $downloadUrl = null;
                            if ($attachment->file_path) {
                                try {
                                    $downloadUrl = Storage::disk('supabase')->temporaryUrl(
                                        $attachment->file_path,
                                        now()->addMinutes(60)
                                    );
                                } catch (\Exception $e) {
                                    Log::error('Error generating national info attachment URL: ' . $e->getMessage());
                                    $downloadUrl = null;
                                }
                            }
                            
                            return [
                                'id' => $attachment->id,
                                'file_name' => $attachment->file_name,
                                'file_size' => (int)$attachment->file_size,
                                'download_url' => $downloadUrl,
                                'created_at' => $attachment->created_at ? Carbon::parse($attachment->created_at)->format('Y-m-d H:i:s') : null
                            ];
                        })
                        ->toArray();
                    
                    $attachments = $attachmentRecords;
                }

                // Truncate content for preview
                $contentPreview = $item->content;
                if (strlen($contentPreview) > 200) {
                    $contentPreview = substr($contentPreview, 0, 200) . '...';
                }

                return [
                    'id' => $item->id,
                    'type' => 'national_information',
                    'info_type' => $item->type,
                    'title' => $item->title,
                    'content' => $item->content,
                    'content_preview' => $contentPreview,
                    'category' => $item->category,
                    'author' => $item->author,
                    'status' => $item->status,
                    'published_at' => $item->published_at ? Carbon::parse($item->published_at)->format('Y-m-d H:i:s') : null,
                    'created_at' => $item->created_at ? Carbon::parse($item->created_at)->format('Y-m-d H:i:s') : null,
                    'updated_at' => $item->updated_at ? Carbon::parse($item->updated_at)->format('Y-m-d H:i:s') : null,
                    'attachment_count' => (int)$item->attachment_count,
                    'attachments' => $attachments,
                    'result_type' => $item->result_type
                ];
            })
            ->toArray();
    } catch (\Exception $e) {
        Log::error('Error in searchOrganizationInformationOnly: ' . $e->getMessage());
        return [];
    }
}

/**
 * Quick search for global search bar (returns limited results)
 */
public function quickSearch(Request $request)
{
    try {
        $searchTerm = $request->get('q', '');
        $limit = $request->get('limit', 7); // Increased to accommodate more types
        
        if (empty($searchTerm)) {
            return response()->json([
                'success' => true,
                'data' => [],
                'message' => 'Please provide a search term'
            ]);
        }
        
        // Get results from each type
        $members = $this->searchMembersOnly($searchTerm, 2);
        $documents = $this->searchDocumentsOnly($searchTerm, 2);
        $affiliates = $this->searchAffiliatesOnly($searchTerm, 1);
        $links = $this->searchLinksOnly($searchTerm, 1);
        $nationalInfo = $this->searchOrganizationInformationOnly($searchTerm, 1);
        
        $results = [];
        
        // Format for quick results
        foreach ($members as $member) {
            $results[] = [
                'id' => $member['id'],
                'type' => 'member',
                'title' => $member['name'],
                'subtitle' => $member['member_id'] . ' • ' . $member['affiliate_name'],
                'icon' => 'person',
                'url' => '/members/' . $member['id']
            ];
        }
        
        foreach ($documents as $document) {
            $results[] = [
                'id' => $document['id'],
                'type' => 'document',
                'title' => $document['title'],
                'subtitle' => $document['category'] . ' • ' . $document['affiliate_name'],
                'icon' => 'description',
                'url' => '/documents/' . $document['id']
            ];
        }
        
        foreach ($affiliates as $affiliate) {
            $results[] = [
                'id' => $affiliate['id'],
                'type' => 'affiliate',
                'title' => $affiliate['name'],
                'subtitle' => $affiliate['affiliate_type'] . ' • ' . $affiliate['member_count'] . ' members',
                'icon' => 'business',
                'url' => '/affiliates/' . $affiliate['id']
            ];
        }
        
        foreach ($links as $link) {
            $results[] = [
                'id' => $link['id'],
                'type' => 'link',
                'title' => $link['title'],
                'subtitle' => ($link['category'] ? $link['category'] . ' • ' : '') . ($link['affiliate_name'] ? $link['affiliate_name'] : 'Organization'),
                'icon' => 'link',
                'url' => $link['url'],
                'external' => true // Mark as external link
            ];
        }
        
        foreach ($nationalInfo as $info) {
            $results[] = [
                'id' => $info['id'],
                'type' => 'national_information',
                'title' => $info['title'],
                'subtitle' => ucfirst($info['info_type']) . ($info['category'] ? ' • ' . $info['category'] : ''),
                'icon' => 'info',
                'url' => '/national-information/' . $info['id']
            ];
        }
        
        return response()->json([
            'success' => true,
            'data' => $results,
            'message' => 'Quick search completed',
            'total_results' => count($results),
            'breakdown' => [
                'members' => count($members),
                'documents' => count($documents),
                'affiliates' => count($affiliates),
                'links' => count($links),
                'national_information' => count($nationalInfo)
            ]
        ]);
        
    } catch (\Exception $e) {
        Log::error('Quick search error: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Failed to perform quick search'
        ], 500);
    }
}

/**
 * Search members only
 */
private function searchMembersOnly($searchTerm, $limit = 10, $affiliateId = null, $memberLevel = null, $status = null)
    {
        try {
            $schema = config('database.connections.pgsql.search_path');
            
            if (empty($searchTerm)) {
                return [];
            }
            
            return DB::table($schema . '.members as m')
                ->select(
                    'm.id',
                    DB::raw("CONCAT(m.first_name, ' ', m.last_name) as name"),
                    'm.member_id',
                    'm.level',
                    'm.status',
                    'm.public_uid',
                    'm.work_email',
                    'm.work_phone',
                    'm.profile_photo_url',
                    'm.address_line1',
                    'm.city',
                    'm.state',
                    'm.zip_code',
                    'a.name as affiliate_name',
                    'a.logo_url as affiliate_logo_url',
                    DB::raw("'member' as result_type")
                )
                ->leftJoin($schema . '.affiliates as a', 'm.affiliate_id', '=', 'a.id')
                ->where(function ($query) use ($searchTerm) {
                    $query->where('m.first_name', 'ilike', "%{$searchTerm}%")
                        ->orWhere('m.last_name', 'ilike', "%{$searchTerm}%")
                        ->orWhere('m.member_id', 'ilike', "%{$searchTerm}%")
                        ->orWhere('m.work_email', 'ilike', "%{$searchTerm}%")
                        ->orWhere('m.work_phone', 'ilike', "%{$searchTerm}%")
                        ->orWhere('m.city', 'ilike', "%{$searchTerm}%")
                        ->orWhere('m.state', 'ilike', "%{$searchTerm}%")
                        ->orWhere('a.name', 'ilike', "%{$searchTerm}%");
                })
                ->when($affiliateId, function ($query) use ($affiliateId) {
                    return $query->where('m.affiliate_id', $affiliateId);
                })
                ->when($memberLevel, function ($query) use ($memberLevel) {
                    if ($memberLevel === 'Not Specified') {
                        return $query->whereNull('m.level');
                    }
                    return $query->where('m.level', $memberLevel);
                })
                ->when($status, function ($query) use ($status) {
                    return $query->where('m.status', $status);
                })
                ->orderByRaw("
                    CASE 
                        WHEN m.first_name ILIKE ? THEN 1
                        WHEN m.last_name ILIKE ? THEN 2
                        WHEN m.member_id ILIKE ? THEN 3
                        ELSE 4
                    END
                ", ["%{$searchTerm}%", "%{$searchTerm}%", "%{$searchTerm}%"])
                ->limit($limit)
                ->get()
                ->map(function ($item) {
                    // Generate member profile photo URL
                    $profilePhotoUrl = null;
                    if ($item->profile_photo_url) {
                        try {
                            $profilePhotoUrl = Storage::disk('supabase')->temporaryUrl(
                                $item->profile_photo_url,
                                now()->addMinutes(60)
                            );
                        } catch (\Exception $e) {
                            Log::error('Error generating profile photo URL: ' . $e->getMessage());
                            $profilePhotoUrl = null;
                        }
                    }

                    // Generate affiliate logo URL
                    $affiliateLogoUrl = null;
                    if ($item->affiliate_logo_url) {
                        try {
                            $affiliateLogoUrl = Storage::disk('supabase')->temporaryUrl(
                                $item->affiliate_logo_url,
                                now()->addMinutes(60)
                            );
                        } catch (\Exception $e) {
                            Log::error('Error generating affiliate logo URL: ' . $e->getMessage());
                            $affiliateLogoUrl = null;
                        }
                    }

                    return [
                        'id' => $item->id,
                        'type' => 'member',
                        'public_uid' => $item->public_uid,
                        'name' => $item->name,
                        'member_id' => $item->member_id,
                        'level' => $item->level,
                        'status' => $item->status,
                        'work_email' => $item->work_email,
                        'work_phone' => $item->work_phone,
                        'address_line1' => $item->address_line1,
                        'city' => $item->city,
                        'state' => $item->state,
                        'zip_code' => $item->zip_code,
                        'profile_photo_url' => $profilePhotoUrl,
                        'affiliate_name' => $item->affiliate_name,
                        'affiliate_logo_url' => $affiliateLogoUrl,
                        'result_type' => $item->result_type
                    ];
                })
                ->toArray();
        } catch (\Exception $e) {
            Log::error('Error in searchMembersOnly: ' . $e->getMessage());
            return [];
        }
    }



/**
 * Search documents only
 */
 private function searchDocumentsOnly($searchTerm, $limit = 10, $affiliateId = null)
    {
        try {
            $schema = config('database.connections.pgsql.search_path');
            
            if (!Schema::hasTable($schema . '.documents')) {
                return [];
            }
            
            if (empty($searchTerm)) {
                return [];
            }
            
            return DB::table($schema . '.documents as d')
                ->select(
                    'd.id',
                    'd.title',
                    'd.description',
                    'd.type',
                    'd.category',
                    'd.file_name',
                    'd.file_path',
                    'd.created_at',
                    'd.updated_at',
                    'a.name as affiliate_name',
                    'a.logo_url as affiliate_logo_url',
                    DB::raw("'document' as result_type")
                )
                ->leftJoin($schema . '.affiliates as a', 'd.affiliate_id', '=', 'a.id')
                ->where(function ($query) use ($searchTerm) {
                    $query->where('d.title', 'ilike', "%{$searchTerm}%")
                        ->orWhere('d.description', 'ilike', "%{$searchTerm}%")
                        ->orWhere('d.category', 'ilike', "%{$searchTerm}%")
                        ->orWhere('d.type', 'ilike', "%{$searchTerm}%")
                        ->orWhere('d.file_name', 'ilike', "%{$searchTerm}%")
                        ->orWhere('d.keywords', 'ilike', "%{$searchTerm}%")
                        ->orWhere('a.name', 'ilike', "%{$searchTerm}%");
                })
                ->when($affiliateId, function ($query) use ($affiliateId) {
                    return $query->where('d.affiliate_id', $affiliateId);
                })
                ->where('d.is_active', true)
                ->orderByRaw("
                    CASE 
                        WHEN d.title ILIKE ? THEN 1
                        WHEN d.description ILIKE ? THEN 2
                        WHEN d.category ILIKE ? THEN 3
                        ELSE 4
                    END
                ", ["%{$searchTerm}%", "%{$searchTerm}%", "%{$searchTerm}%"])
                ->orderBy('d.updated_at', 'desc')
                ->limit($limit)
                ->get()
                ->map(function ($item) {
                    // Generate document download URL
                    $downloadUrl = null;
                    if ($item->file_path) {
                        try {
                            $downloadUrl = Storage::disk('supabase')->temporaryUrl(
                                $item->file_path,
                                now()->addMinutes(60)
                            );
                        } catch (\Exception $e) {
                            Log::error('Error generating document download URL: ' . $e->getMessage());
                            $downloadUrl = null;
                        }
                    }

                    // Generate affiliate logo URL
                    $affiliateLogoUrl = null;
                    if ($item->affiliate_logo_url) {
                        try {
                            $affiliateLogoUrl = Storage::disk('supabase')->temporaryUrl(
                                $item->affiliate_logo_url,
                                now()->addMinutes(60)
                            );
                        } catch (\Exception $e) {
                            Log::error('Error generating affiliate logo URL: ' . $e->getMessage());
                            $affiliateLogoUrl = null;
                        }
                    }

                    return [
                        'id' => $item->id,
                        'type' => 'document',
                        'title' => $item->title,
                        'description' => $item->description,
                        'document_type' => $item->type,
                        'category' => $item->category,
                        'file_name' => $item->file_name,
                        'download_url' => $downloadUrl,
                        'created_at' => $item->created_at ? Carbon::parse($item->created_at)->format('Y-m-d H:i:s') : null,
                        'updated_at' => $item->updated_at ? Carbon::parse($item->updated_at)->format('Y-m-d H:i:s') : null,
                        'affiliate_name' => $item->affiliate_name,
                        'affiliate_logo_url' => $affiliateLogoUrl,
                        'result_type' => $item->result_type
                    ];
                })
                ->toArray();
        } catch (\Exception $e) {
            Log::error('Error in searchDocumentsOnly: ' . $e->getMessage());
            return [];
        }
    }

/**
 * Search affiliates only
 */
 private function searchAffiliatesOnly($searchTerm, $limit = 10, $affiliateId = null)
    {
        try {
            $schema = config('database.connections.pgsql.search_path');
            
            if (empty($searchTerm)) {
                return [];
            }
            
            return DB::table($schema . '.affiliates as a')
                ->select(
                    'a.id',
                    'a.name',
                    'a.affiliate_type',
                    'a.state',
                    'a.public_uid',
                    'a.employer_name',
                    'a.cbc_region',
                    'a.org_region',
                    'a.logo_url',
                    'a.created_at',
                    'a.updated_at',
                    DB::raw('COUNT(m.id) as member_count'),
                    DB::raw("'affiliate' as result_type")
                )
                ->leftJoin($schema . '.members as m', 'a.id', '=', 'm.affiliate_id')
                ->where(function ($query) use ($searchTerm) {
                    $query->where('a.name', 'ilike', "%{$searchTerm}%")
                        ->orWhere('a.affiliate_type', 'ilike', "%{$searchTerm}%")
                        ->orWhere('a.state', 'ilike', "%{$searchTerm}%")
                        ->orWhere('a.employer_name', 'ilike', "%{$searchTerm}%")
                        ->orWhere('a.cbc_region', 'ilike', "%{$searchTerm}%")
                        ->orWhere('a.org_region', 'ilike', "%{$searchTerm}%");
                })
                ->when($affiliateId, function ($query) use ($affiliateId) {
                    return $query->where('a.id', $affiliateId);
                })
                ->groupBy('a.id', 'a.name', 'a.affiliate_type', 'a.state', 'a.employer_name', 
                         'a.cbc_region', 'a.org_region', 'a.logo_url', 'a.created_at', 'a.updated_at')
                ->orderByRaw("
                    CASE 
                        WHEN a.name ILIKE ? THEN 1
                        WHEN a.affiliate_type ILIKE ? THEN 2
                        WHEN a.state ILIKE ? THEN 3
                        ELSE 4
                    END
                ", ["%{$searchTerm}%", "%{$searchTerm}%", "%{$searchTerm}%"])
                ->limit($limit)
                ->get()
                ->map(function ($item) {
                    // Generate affiliate logo URL
                    $logoUrl = null;
                    if ($item->logo_url) {
                        try {
                            $logoUrl = Storage::disk('supabase')->temporaryUrl(
                                $item->logo_url,
                                now()->addMinutes(60)
                            );
                        } catch (\Exception $e) {
                            Log::error('Error generating affiliate logo URL: ' . $e->getMessage());
                            $logoUrl = null;
                        }
                    }

                    return [
                        'id' => $item->id,
                        'type' => 'affiliate',
                        'public_uid' => $item->public_uid,
                        'name' => $item->name,
                        'affiliate_type' => $item->affiliate_type,
                        'state' => $item->state,
                        'employer_name' => $item->employer_name,
                        'cbc_region' => $item->cbc_region,
                        'org_region' => $item->org_region,
                        'member_count' => (int)$item->member_count,
                        'logo_url' => $logoUrl,
                        'created_at' => $item->created_at ? Carbon::parse($item->created_at)->format('Y-m-d H:i:s') : null,
                        'updated_at' => $item->updated_at ? Carbon::parse($item->updated_at)->format('Y-m-d H:i:s') : null,
                        'result_type' => $item->result_type
                    ];
                })
                ->toArray();
        } catch (\Exception $e) {
            Log::error('Error in searchAffiliatesOnly: ' . $e->getMessage());
            return [];
        }
    }


/**
 * Calculate search relevance score (simple version)
 */
private function calculateSearchScore($searchTerm, $text)
{
    if (empty($text)) {
        return 0;
    }
    
    $searchTerm = strtolower($searchTerm);
    $text = strtolower($text);
    
    // Simple scoring: exact match = 100, partial match = 50, contains = 25
    if ($text === $searchTerm) {
        return 100;
    } elseif (strpos($text, $searchTerm) === 0) {
        return 75;
    } elseif (strpos($text, $searchTerm) !== false) {
        return 50;
    }
    
    // Word-by-word matching
    $searchWords = explode(' ', $searchTerm);
    $textWords = explode(' ', $text);
    $matchedWords = 0;
    
    foreach ($searchWords as $searchWord) {
        foreach ($textWords as $textWord) {
            if (strpos($textWord, $searchWord) !== false) {
                $matchedWords++;
                break;
            }
        }
    }
    
    if ($matchedWords > 0) {
        return ($matchedWords / count($searchWords)) * 100;
    }
    
    return 0;
}


}