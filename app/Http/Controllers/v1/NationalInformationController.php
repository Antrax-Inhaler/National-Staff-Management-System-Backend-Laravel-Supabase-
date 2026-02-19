<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiCollection;
use App\Models\OrganizationInformation;
use App\Models\ActivityLog;
use App\Models\OrganizationInformationAttachment;
use App\Models\Member;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class OrganizationInformationController extends Controller
{
 const TYPES = ['announcement', 'news', 'resource', 'event', 'policy'];
    const CATEGORIES = ['general', 'membership', 'events', 'resources', 'policies', 'updates'];
    const STATUSES = ['draft', 'published', 'archived'];

    /**
     * Get viewers for a specific article
     */
    public function getViewers($id): JsonResponse
    {
        try {
            Log::info('Fetching viewers for article:', ['article_id' => $id]);
            
            // Get the article
            $article = OrganizationInformation::find($id);
            if (!$article) {
                return response()->json([
                    'success' => false,
                    'message' => 'Article not found'
                ], 404);
            }

            // Get unique users who viewed this article
            $viewers = ActivityLog::where('auditable_type', OrganizationInformation::class)
                ->where('auditable_id', $id)
                ->where('action', 'view')
                ->with(['user.member' => function($query) {
                    $query->select([
                        'id',
                        'user_id',
                        'public_uid',
                        'first_name',
                        'last_name',
                        'profile_photo_url',
                        'member_id',
                        'level',
                        'employment_status',
                        'affiliate_id'
                    ]);
                }])
                ->select(['user_id', DB::raw('MAX(created_at) as last_viewed_at'), DB::raw('COUNT(*) as view_count')])
                ->groupBy('user_id')
                ->orderBy('last_viewed_at', 'desc')
                ->get()
                ->map(function ($activity) {
                    $user = $activity->user;
                    $member = $user->member ?? null;
                    
                    return [
                        'user_id' => $activity->user_id,
                        'last_viewed_at' => $activity->last_viewed_at,
                        'view_count' => $activity->view_count,
                        'member' => $member ? [
                            'id' => $member->id,
                            'full_name' => $member->first_name . ' ' . $member->last_name,
                            'first_name' => $member->first_name,
                            'public_uid' => $member->public_uid,
                            'last_name' => $member->last_name,
                            'member_id' => $member->member_id,
                            'level' => $member->level,
                            'employment_status' => $member->employment_status,
                            'profile_photo_url' => $member->profile_photo_url 
                                ? $this->generateTemporaryUrl($member->profile_photo_url, 10)
                                : null,
                            'affiliate_id' => $member->affiliate_id,
                        ] : null,
                        'user' => $user ? [
                            'email' => $user->email,
                            'email_verified_at' => $user->email_verified_at,
                        ] : null
                    ];
                });

            // Get affiliate names for members
            $affiliateIds = $viewers->pluck('member.affiliate_id')->filter()->unique();
            $affiliates = \App\Models\Affiliate::whereIn('id', $affiliateIds)
                ->select(['id', 'name'])
                ->get()
                ->keyBy('id');

            // Add affiliate name to each viewer
            $viewers = $viewers->map(function ($viewer) use ($affiliates) {
                if ($viewer['member'] && isset($viewer['member']['affiliate_id'])) {
                    $affiliate = $affiliates->get($viewer['member']['affiliate_id']);
                    $viewer['member']['affiliate_name'] = $affiliate ? $affiliate->name : null;
                }
                return $viewer;
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'article' => [
                        'id' => $article->id,
                        'title' => $article->title,
                        'total_viewers' => $viewers->count(),
                        'total_views' => $viewers->sum('view_count')
                    ],
                    'viewers' => $viewers
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to get viewers: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch viewers'
            ], 500);
        }
    }

    /**
     * Get simplified viewer list (just counts for table display)
     */
    public function getViewerStats($id): JsonResponse
    {
        try {
            // Get total unique viewers
            $totalViewers = ActivityLog::where('auditable_type', OrganizationInformation::class)
                ->where('auditable_id', $id)
                ->where('action', 'view')
                ->distinct('user_id')
                ->count('user_id');

            // Get total view count
            $totalViews = ActivityLog::where('auditable_type', OrganizationInformation::class)
                ->where('auditable_id', $id)
                ->where('action', 'view')
                ->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'total_viewers' => $totalViewers,
                    'total_views' => $totalViews
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to get viewer stats: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch viewer stats'
            ], 500);
        }
    }

public function options(): JsonResponse
    {
        try {
            $options = [
                'types' => self::TYPES,
                'categories' => self::CATEGORIES,
                'statuses' => self::STATUSES,
                'authors' => OrganizationInformation::distinct('author')
                    ->whereNotNull('author')
                    ->orderBy('author')
                    ->pluck('author')
                    ->toArray(),
            ];

            return response()->json([
                'success' => true,
                'data' => $options
            ]);
        } catch (\Exception $e) {
            Log::error('Organization Information Options Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get options'
            ], 500);
        }
    }

public function statistics(): JsonResponse
{
    try {
        $userId = Auth::id();
        
        // Get all published articles
        $totalArticles = OrganizationInformation::count();
        $publishedArticles = OrganizationInformation::where('status', 'published')->count();
        
        // Get articles that the current user has viewed
        $viewedArticles = ActivityLog::where('user_id', $userId)
            ->where('auditable_type', OrganizationInformation::class)
            ->where('action', 'view')
            ->pluck('auditable_id')
            ->toArray();
        
        // Get unread articles count
        $unreadArticles = OrganizationInformation::where('status', 'published')
            ->whereNotIn('id', $viewedArticles ?: [0])
            ->count();
        
        // Get unread articles by type
        $unreadByType = OrganizationInformation::where('status', 'published')
            ->whereNotIn('id', $viewedArticles ?: [0])
            ->select('type', DB::raw('count(*) as count'))
            ->groupBy('type')
            ->get()
            ->pluck('count', 'type');
        
        // Get total articles by type (for comparison)
        $byType = OrganizationInformation::select('type', DB::raw('count(*) as count'))
            ->where('status', 'published')
            ->groupBy('type')
            ->get()
            ->pluck('count', 'type');
        
        // Get total unique views (across all users and articles)
        $totalViews = ActivityLog::where('auditable_type', OrganizationInformation::class)
            ->where('action', 'view')
            ->select(DB::raw('COUNT(DISTINCT CONCAT(user_id, \'-\', auditable_id)) as count'))
            ->value('count') ?? 0;
        
        // Get most viewed articles
        $mostViewed = ActivityLog::where('auditable_type', OrganizationInformation::class)
            ->where('action', 'view')
            ->select('auditable_id', DB::raw('COUNT(DISTINCT user_id) as unique_viewers'))
            ->groupBy('auditable_id')
            ->orderByDesc('unique_viewers')
            ->limit(5)
            ->get()
            ->map(function ($item) {
                $article = OrganizationInformation::find($item->auditable_id);
                return [
                    'id' => $item->auditable_id,
                    'title' => $article ? $article->title : 'Deleted Article',
                    'unique_viewers' => $item->unique_viewers
                ];
            });
        
        // Get view statistics by date (last 30 days)
        $recentViews = ActivityLog::where('auditable_type', OrganizationInformation::class)
            ->where('action', 'view')
            ->where('created_at', '>=', now()->subDays(30))
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(DISTINCT user_id) as daily_viewers'))
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date', 'desc')
            ->get();
        
        $stats = [
            'total' => $totalArticles,
            'published' => $publishedArticles,
            'draft' => OrganizationInformation::where('status', 'draft')->count(),
            'archived' => OrganizationInformation::where('status', 'archived')->count(),
            'unread' => $unreadArticles, // Total unread articles for current user
            'total_views' => $totalViews,
            'most_viewed' => $mostViewed,
            'recent_views' => $recentViews,
            'by_type' => $byType, // Total published articles by type
            'unread_by_type' => $unreadByType, // Unread articles by type for current user
            'by_category' => OrganizationInformation::select('category', DB::raw('count(*) as count'))
                ->where('status', 'published')
                ->groupBy('category')
                ->get()
                ->pluck('count', 'category'),
        ];
        
        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    } catch (\Exception $e) {
        Log::error('Organization Information Statistics Error: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Failed to get statistics'
        ], 500);
    }
}
public function getUnreadArticlesByType(Request $request, $type): JsonResponse
{
    try {
        $userId = Auth::id();
        
        // Validate type
        if (!in_array($type, self::TYPES)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid type'
            ], 400);
        }
        
        // Get articles that user has viewed
        $viewedArticles = ActivityLog::where('user_id', $userId)
            ->where('auditable_type', OrganizationInformation::class)
            ->where('action', 'view')
            ->pluck('auditable_id')
            ->toArray();
        
        // Get unread articles of specific type
        $query = OrganizationInformation::with(['attachments'])
            ->where('type', $type)
            ->where('status', 'published')
            ->whereNotIn('id', $viewedArticles ?: [0])
            ->orderBy('published_at', 'desc');
        
        // Search filter
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'ilike', "%{$search}%")
                  ->orWhere('content', 'ilike', "%{$search}%");
            });
        }
        
        // Category filter
        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }
        
        // Add view count subquery
        $query->addSelect([
            'view_count' => ActivityLog::select(DB::raw('COUNT(DISTINCT user_id)'))
                ->whereColumn('auditable_id', 'national_information.id')
                ->where('auditable_type', OrganizationInformation::class)
                ->where('action', 'view')
        ])->withCasts(['view_count' => 'integer']);
        
        // Mark all as unread
        $query->addSelect(DB::raw('true as is_unread'));
        
        // Pagination
        $perPage = (int) $request->query('per_page', 20);
        
        // Create the ApiCollection and return its response
        $collection = ApiCollection::makeFromQuery($query, $perPage, 'published_at', 'desc');
        
        // Get the paginated data
        $paginatedData = $collection->toResponse($request)->getData(true);
        
        // Add temporary URLs to attachments
        if (isset($paginatedData['data'])) {
            foreach ($paginatedData['data'] as &$item) {
                // Add shareable URL
                $item['share_url'] = url("/national-information/{$item['public_uid']}");
                
                if (isset($item['attachments']) && is_array($item['attachments'])) {
                    foreach ($item['attachments'] as &$attachment) {
                        if (isset($attachment['file_path'])) {
                            try {
                                $attachment['file_url'] = Storage::disk('supabase')->temporaryUrl(
                                    $attachment['file_path'],
                                    now()->addMinutes(60) 
                                );
                            } catch (\Exception $e) {
                                Log::error('Failed to generate URL for attachment: ' . $attachment['file_path'], [
                                    'error' => $e->getMessage()
                                ]);
                                $attachment['file_url'] = null;
                            }
                        }
                    }
                }
            }
        }
        
        // Add metadata about the request
        $paginatedData['meta']['type'] = $type;
        $paginatedData['meta']['filter'] = 'unread';
        $paginatedData['meta']['unread_count'] = $paginatedData['meta']['total'] ?? 0;
        
        return response()->json($paginatedData);
        
    } catch (\Exception $e) {
        Log::error('Failed to get unread articles by type: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Failed to fetch unread articles'
        ], 500);
    }
}

/**
 * Get all unread articles (across all types)
 */
public function getAllUnreadArticles(Request $request): JsonResponse
{
    try {
        $userId = Auth::id();
        
        // Get articles that user has viewed
        $viewedArticles = ActivityLog::where('user_id', $userId)
            ->where('auditable_type', OrganizationInformation::class)
            ->where('action', 'view')
            ->pluck('auditable_id')
            ->toArray();
        
        // Get all unread articles
        $query = OrganizationInformation::with(['attachments'])
            ->where('status', 'published')
            ->whereNotIn('id', $viewedArticles ?: [0])
            ->orderBy('published_at', 'desc');
        
        // Type filter
        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        
        // Search filter
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'ilike', "%{$search}%")
                  ->orWhere('content', 'ilike', "%{$search}%");
            });
        }
        
        // Category filter
        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }
        
        // Add view count subquery
        $query->addSelect([
            'view_count' => ActivityLog::select(DB::raw('COUNT(DISTINCT user_id)'))
                ->whereColumn('auditable_id', 'national_information.id')
                ->where('auditable_type', OrganizationInformation::class)
                ->where('action', 'view')
        ])->withCasts(['view_count' => 'integer']);
        
        // Mark all as unread
        $query->addSelect(DB::raw('true as is_unread'));
        
        // Pagination
        $perPage = (int) $request->query('per_page', 20);
        
        // Create the ApiCollection and return its response
        $collection = ApiCollection::makeFromQuery($query, $perPage, 'published_at', 'desc');
        
        // Get the paginated data
        $paginatedData = $collection->toResponse($request)->getData(true);
        
        // Add temporary URLs to attachments
        if (isset($paginatedData['data'])) {
            foreach ($paginatedData['data'] as &$item) {
                // Add shareable URL
                $item['share_url'] = url("/national-information/{$item['public_uid']}");
                
                if (isset($item['attachments']) && is_array($item['attachments'])) {
                    foreach ($item['attachments'] as &$attachment) {
                        if (isset($attachment['file_path'])) {
                            try {
                                $attachment['file_url'] = Storage::disk('supabase')->temporaryUrl(
                                    $attachment['file_path'],
                                    now()->addMinutes(60) 
                                );
                            } catch (\Exception $e) {
                                Log::error('Failed to generate URL for attachment: ' . $attachment['file_path'], [
                                    'error' => $e->getMessage()
                                ]);
                                $attachment['file_url'] = null;
                            }
                        }
                    }
                }
            }
        }
        
        // Add metadata
        $paginatedData['meta']['filter'] = 'unread';
        $paginatedData['meta']['unread_count'] = $paginatedData['meta']['total'] ?? 0;
        
        return response()->json($paginatedData);
        
    } catch (\Exception $e) {
        Log::error('Failed to get all unread articles: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Failed to fetch unread articles'
        ], 500);
    }
}
// Add this method to your backend controller:
public function bulkDelete(Request $request): JsonResponse
{
    try {
        Log::info('Bulk delete request:', $request->all());

        // Validate request
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:national_information,id'],
        ]);

        DB::beginTransaction();

        $ids = $validated['ids'];
        $deletedCount = 0;

        // Delete each record
        foreach ($ids as $id) {
            $nationalInfo = OrganizationInformation::find($id);
            
            if ($nationalInfo) {
                $nationalInfo->delete();
                $deletedCount++;
                
                Log::info('Deleted item:', [
                    'id' => $id,
                    'title' => $nationalInfo->title,
                ]);
            }
        }

        DB::commit();

        Log::info('Bulk delete completed:', [
            'total_ids' => count($ids),
            'deleted_count' => $deletedCount,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Successfully deleted {$deletedCount} item(s)",
            'data' => [
                'deleted_count' => $deletedCount,
                'total_ids' => count($ids),
            ]
        ]);

    } catch (\Illuminate\Validation\ValidationException $e) {
        DB::rollBack();
        Log::error('Bulk delete validation error:', $e->errors());
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $e->errors()
        ], 422);
    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Bulk delete error: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString()
        ]);
        return response()->json([
            'success' => false,
            'message' => 'Failed to perform bulk delete: ' . $e->getMessage()
        ], 500);
    }
}
    /**
     * Update show method to include view statistics
     */
public function bulkUpdate(Request $request): JsonResponse
{
    try {
        Log::info('Bulk update request:', $request->all());

        // Validate request
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:national_information,id'],
            'status' => ['required', 'string', Rule::in(self::STATUSES)],
            'publish_date' => ['nullable', 'date'], // Add publish date
            'publish_time' => ['nullable', 'date_format:H:i'], // Add publish time
        ]);

        Log::info('Validated bulk update data:', $validated);

        DB::beginTransaction();

        $ids = $validated['ids'];
        $status = $validated['status'];
        $publishDate = isset($validated['publish_date']) ? $validated['publish_date'] : null;
        $publishTime = isset($validated['publish_time']) ? $validated['publish_time'] : null;
        $updatedCount = 0;

        // Update each record
        foreach ($ids as $id) {
            $nationalInfo = OrganizationInformation::find($id);
            
            if ($nationalInfo) {
                $updateData = ['status' => $status];
                
                // Handle publish date/time for scheduled publishing
                if ($status === 'published') {
                    if ($publishDate && $publishTime) {
                        // Combine date and time for scheduled publishing
                        $publishDateTime = Carbon::createFromFormat('Y-m-d H:i', $publishDate . ' ' . $publishTime);
                        $updateData['published_at'] = $publishDateTime;
                    } elseif (!$nationalInfo->published_at) {
                        // If no publish date set and not scheduled, set to now
                        $updateData['published_at'] = now();
                    }
                    // If already has publish date and not scheduled, keep existing date
                } elseif ($status === 'draft') {
                    // If changing back to draft, clear publish date
                    $updateData['published_at'] = null;
                }
                
                $nationalInfo->update($updateData);
                
                $updatedCount++;
                
                Log::info('Updated item:', [
                    'id' => $id,
                    'old_status' => $nationalInfo->getOriginal('status'),
                    'new_status' => $status,
                    'publish_date' => $updateData['published_at'] ?? null,
                    'title' => $nationalInfo->title,
                ]);
            }
        }

        DB::commit();

        Log::info('Bulk update completed:', [
            'total_ids' => count($ids),
            'updated_count' => $updatedCount,
            'status' => $status,
            'has_schedule' => !empty($publishDate) && !empty($publishTime),
        ]);

        $message = "Successfully updated {$updatedCount} item(s) to {$status} status";
        if ($status === 'published' && $publishDate && $publishTime) {
            $message .= " (scheduled for {$publishDate} at {$publishTime})";
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'updated_count' => $updatedCount,
                'total_ids' => count($ids),
                'status' => $status,
                'publish_date' => $publishDate,
                'publish_time' => $publishTime,
            ]
        ]);

    } catch (\Illuminate\Validation\ValidationException $e) {
        DB::rollBack();
        Log::error('Bulk update validation error:', $e->errors());
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $e->errors()
        ], 422);
    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Bulk update error: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString()
        ]);
        return response()->json([
            'success' => false,
            'message' => 'Failed to perform bulk update: ' . $e->getMessage()
        ], 500);
    }
}
    public function show($identifier): JsonResponse
    {
        try {
            Log::info('Organization Information Show Request:', ['identifier' => $identifier]);
            
            // Build query
            $query = OrganizationInformation::with(['attachments']);
            
            // Check if identifier is numeric (ID) or UUID (public_uid)
            if (is_numeric($identifier)) {
                $info = $query->find($identifier);
            } else {
                // If not numeric, assume it's public_uid
                $info = $query->where('public_uid', $identifier)->first();
            }

            if (!$info) {
                return response()->json([
                    'success' => false,
                    'message' => 'Information not found'
                ], 404);
            }

            // Log view activity - ONE VIEW PER USER PER ARTICLE
            $this->logViewActivity($info);

            // Add full URLs for attachments
            $info->attachments->each(function ($attachment) {
                $attachment->file_url = Storage::disk('supabase')->temporaryUrl(
                    $attachment->file_path,
                    now()->addMinutes(10)
                );
            });

            // Get view statistics
            $viewStats = $this->getArticleViewStats($info->id);

            // Add view statistics to response
            $info->view_count = $viewStats['total_viewers'];
            $info->reader_count = $viewStats['total_viewers']; // For compatibility
            $info->total_views = $viewStats['total_views'];
            $info->view_stats = $viewStats;

            return response()->json([
                'success' => true,
                'data' => $info
            ]);
        } catch (\Exception $e) {
            Log::error('Organization Information Show Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Information not found'
            ], 404);
        }
    }

    /**
     * Update index method to include view counts
     */
public function index(Request $request): JsonResponse
{
    try {
        Log::info('Organization Information List Request:', $request->all());
        
        $query = OrganizationInformation::with(['attachments'])
            ->orderBy('created_at', 'desc');

        // Search filter
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'ilike', "%{$search}%")
                  ->orWhere('content', 'ilike', "%{$search}%")
                  ->orWhere('author', 'ilike', "%{$search}%");
            });
        }

        // Type filter
        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        // Category filter
        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }

        // Status filter
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        // Author filter
        if ($author = $request->query('author')) {
            $query->where('author', $author);
        }

        // Get current user ID for unread status
        $userId = Auth::id();

        // Get articles that user has viewed
        $viewedArticles = ActivityLog::where('user_id', $userId)
            ->where('auditable_type', OrganizationInformation::class)
            ->where('action', 'view')
            ->pluck('auditable_id')
            ->toArray();

        // Add subquery for view counts
        $query->addSelect([
            'view_count' => ActivityLog::select(DB::raw('COUNT(DISTINCT user_id)'))
                ->whereColumn('auditable_id', 'national_information.id')
                ->where('auditable_type', OrganizationInformation::class)
                ->where('action', 'view')
        ])->withCasts(['view_count' => 'integer']);

        // Add unread status
        $query->addSelect(DB::raw('CASE WHEN national_information.id IN ('.implode(',', $viewedArticles ?: [0]).') THEN false ELSE true END as is_unread'));

        // Sorting
        $sortBy = $request->query('sort_by', 'published_at');
        $sortOrder = $request->query('sort_order', 'desc');
        
        $sortableColumns = [
            'title', 'type', 'category', 'author', 
            'status', 'published_at', 'created_at', 'updated_at',
            'view_count' // Add view_count to sortable columns
        ];

        if (in_array($sortBy, $sortableColumns)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $perPage = (int) $request->query('per_page', 20);
        
        // Create the ApiCollection and return its response
        $collection = ApiCollection::makeFromQuery($query, $perPage, $sortBy, $sortOrder);
        
        // Get the paginated data
        $paginatedData = $collection->toResponse($request)->getData(true);
        
        // Add temporary URLs to attachments and calculate unread status
        if (isset($paginatedData['data'])) {
            foreach ($paginatedData['data'] as &$item) {
                // Add shareable URL
                $item['share_url'] = url("/national-information/{$item['public_uid']}");
                
                // Add unread status if not already present
                if (!isset($item['is_unread'])) {
                    $item['is_unread'] = !in_array($item['id'], $viewedArticles);
                }
                
                if (isset($item['attachments']) && is_array($item['attachments'])) {
                    foreach ($item['attachments'] as &$attachment) {
                        if (isset($attachment['file_path'])) {
                            try {
                                $attachment['file_url'] = Storage::disk('supabase')->temporaryUrl(
                                    $attachment['file_path'],
                                    now()->addMinutes(60) 
                                );
                            } catch (\Exception $e) {
                                Log::error('Failed to generate URL for attachment: ' . $attachment['file_path'], [
                                    'error' => $e->getMessage()
                                ]);
                                $attachment['file_url'] = null;
                            }
                        }
                    }
                }
            }
        }
        
        return response()->json($paginatedData);
        
    } catch (\Exception $e) {
        Log::error('Organization Information List Error: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Failed to fetch national information'
        ], 500);
    }
}

    /**
     * Helper method to get article view statistics
     */
    private function getArticleViewStats($articleId): array
    {
        $totalViewers = ActivityLog::where('auditable_type', OrganizationInformation::class)
            ->where('auditable_id', $articleId)
            ->where('action', 'view')
            ->distinct('user_id')
            ->count('user_id');

        $totalViews = ActivityLog::where('auditable_type', OrganizationInformation::class)
            ->where('auditable_id', $articleId)
            ->where('action', 'view')
            ->count();

        return [
            'total_viewers' => $totalViewers,
            'total_views' => $totalViews
        ];
    }

    /**
     * Helper method to generate temporary URL
     */
    private function generateTemporaryUrl($path, $expirationMinutes = 60): ?string
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
     * Log view activity (one view per user per article)
     */
    private function logViewActivity(OrganizationInformation $info): void
    {
        try {
            $userId = Auth::id();
            $ipAddress = request()->ip();
            
            // Check if user has already viewed this article today
            $existingView = ActivityLog::where('user_id', $userId)
                ->where('auditable_type', OrganizationInformation::class)
                ->where('auditable_id', $info->id)
                ->where('action', 'view')
                ->whereDate('created_at', now()->toDateString())
                ->first();

            if (!$existingView) {
                // Log the view activity
                ActivityLog::create([
                    'user_id' => $userId,
                    'action' => 'view',
                    'auditable_type' => OrganizationInformation::class,
                    'auditable_id' => $info->id,
                    'old_values' => null,
                    'new_values' => [
                        'title' => $info->title,
                        'type' => $info->type,
                        'category' => $info->category
                    ],
                    'ip_address' => $ipAddress,
                    'user_agent' => request()->userAgent(),
                    'auditable_name' => $info->title
                ]);
                
                Log::info('View logged for article: ' . $info->title, [
                    'user_id' => $userId,
                    'article_id' => $info->id
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to log view activity: ' . $e->getMessage());
        }
    }

public function store(Request $request): JsonResponse
{
    try {
        DB::beginTransaction();

        // Validate request
        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(self::TYPES)],
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'category' => ['required', 'string', Rule::in(self::CATEGORIES)],
            'author' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', Rule::in(self::STATUSES)],
            'published_at' => ['nullable', 'date'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file', 'max:10240'], // 10MB max per file
        ]);

        // Create national information
        $nationalInfo = OrganizationInformation::create([
            'type' => $validated['type'],
            'title' => $validated['title'],
            'content' => $validated['content'],
            'category' => $validated['category'],
            'author' => $validated['author'],
            'status' => $validated['status'],
            'published_at' => $validated['published_at'] ?? now(),
        ]);

        // Handle file uploads
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $this->uploadAttachment($nationalInfo, $file);
            }
        }

        DB::commit();

        // Reload with attachments
        $nationalInfo->load('attachments');
        
        // Add URLs to attachments
        $nationalInfo->attachments->each(function ($attachment) {
            $attachment->file_url = Storage::disk('supabase')->temporaryUrl(
                $attachment->file_path,
                now()->addMinutes(60)
            );
        });

        return response()->json([
            'success' => true,
            'message' => 'Organization information created successfully',
            'data' => $nationalInfo
        ], 201);

    } catch (\Illuminate\Validation\ValidationException $e) {
        DB::rollBack();
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $e->errors()
        ], 422);
    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Organization Information Store Error: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Failed to create national information'
        ], 500);
    }
}
public function update(Request $request, $id): JsonResponse
{
    try {
        DB::beginTransaction();

        $nationalInfo = OrganizationInformation::findOrFail($id);

        // Validate request
        $validated = $request->validate([
            'type' => ['sometimes', 'required', 'string', Rule::in(self::TYPES)],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'content' => ['sometimes', 'required', 'string'],
            'category' => ['sometimes', 'required', 'string', Rule::in(self::CATEGORIES)],
            'author' => ['sometimes', 'required', 'string', 'max:255'],
            'status' => ['sometimes', 'required', 'string', Rule::in(self::STATUSES)],
            'published_at' => ['nullable', 'date'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file', 'max:10240'], // 10MB max per file
            'delete_attachments' => ['nullable', 'array'],
            'delete_attachments.*' => ['integer', 'exists:national_information_attachments,id'],
        ]);

        // Update national information
        $updateData = [];
        foreach ($validated as $key => $value) {
            if (!in_array($key, ['attachments', 'delete_attachments'])) {
                $updateData[$key] = $value;
            }
        }

        // Handle status change
        if (isset($validated['status']) && $validated['status'] === 'published' && !$nationalInfo->published_at) {
            $updateData['published_at'] = now();
        }

        $nationalInfo->update($updateData);

        // Handle file deletions
        if (isset($validated['delete_attachments'])) {
            foreach ($validated['delete_attachments'] as $attachmentId) {
                $attachment = OrganizationInformationAttachment::find($attachmentId);
                if ($attachment && $attachment->national_info_id === $nationalInfo->id) {
                    Storage::disk('supabase')->delete($attachment->file_path);
                    $attachment->delete();
                }
            }
        }

        // Handle new file uploads
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $this->uploadAttachment($nationalInfo, $file);
            }
        }

        DB::commit();

        // Reload with attachments
        $nationalInfo->load('attachments');
        
        // Add URLs to attachments
        $nationalInfo->attachments->each(function ($attachment) {
            $attachment->file_url = Storage::disk('supabase')->temporaryUrl(
                $attachment->file_path,
                now()->addMinutes(60)
            );
        });

        return response()->json([
            'success' => true,
            'message' => 'Organization information updated successfully',
            'data' => $nationalInfo
        ]);

    } catch (\Illuminate\Validation\ValidationException $e) {
        DB::rollBack();
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $e->errors()
        ], 422);
    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Organization Information Update Error: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Failed to update national information'
        ], 500);
    }
}

    /**
     * Delete national information
     */
    public function destroy($id): JsonResponse
    {
        try {
            DB::beginTransaction();

            $nationalInfo = OrganizationInformation::findOrFail($id);

            // Delete all attachments first
            foreach ($nationalInfo->attachments as $attachment) {
                Storage::disk('supabase')->delete($attachment->file_path);
                $attachment->delete();
            }

            // Delete the national information
            $nationalInfo->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Organization information deleted successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Organization Information Delete Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete national information'
            ], 500);
        }
    }
    private function uploadAttachment(OrganizationInformation $nationalInfo, $file): void
{
    try {
        $fileName = $file->getClientOriginalName();
        $fileSize = $file->getSize();
        $filePath = $file->store('national-information', 'supabase');

        OrganizationInformationAttachment::create([
            'national_info_id' => $nationalInfo->id,
            'file_name' => $fileName,
            'file_path' => $filePath,
            'file_size' => $fileSize,
        ]);
    } catch (\Exception $e) {
        Log::error('Failed to upload attachment: ' . $e->getMessage());
        throw new \Exception('Failed to upload attachment');
    }
}
public function logView(Request $request): JsonResponse
{
    try {
        $validated = $request->validate([
            'auditable_type' => ['required', 'string'],
            'auditable_id' => ['required', 'integer'],
            'action' => ['required', 'string', Rule::in(['view', 'read'])]
        ]);

        // Check if auditable exists
        if ($validated['auditable_type'] === OrganizationInformation::class) {
            $article = OrganizationInformation::find($validated['auditable_id']);
            if (!$article) {
                return response()->json([
                    'success' => false,
                    'message' => 'Article not found'
                ], 404);
            }
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Invalid auditable type'
            ], 400);
        }

        $userId = Auth::id();
        $ipAddress = request()->ip();
        
        // Check if user has already viewed this article today
        $existingView = ActivityLog::where('user_id', $userId)
            ->where('auditable_type', $validated['auditable_type'])
            ->where('auditable_id', $validated['auditable_id'])
            ->where('action', $validated['action'])
            ->whereDate('created_at', now()->toDateString())
            ->first();

        if (!$existingView) {
            // Log the view activity
            ActivityLog::create([
                'user_id' => $userId,
                'action' => $validated['action'],
                'auditable_type' => $validated['auditable_type'],
                'auditable_id' => $validated['auditable_id'],
                'old_values' => null,
                'new_values' => [
                    'title' => $article->title,
                    'type' => $article->type,
                    'category' => $article->category
                ],
                'ip_address' => $ipAddress,
                'user_agent' => request()->userAgent(),
                'auditable_name' => $article->title
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'View logged successfully'
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'View already logged today'
        ]);

    } catch (\Exception $e) {
        Log::error('Log View Error: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Failed to log view'
        ], 500);
    }
}
public function getUnreadArticles(Request $request): JsonResponse
{
    try {
        $userId = Auth::id();
        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'User not authenticated'
            ], 401);
        }
        
        // Get articles that user has viewed
        $viewedArticles = ActivityLog::where('user_id', $userId)
            ->where('auditable_type', OrganizationInformation::class)
            ->where('action', 'view')
            ->pluck('auditable_id')
            ->toArray();
        
        // Base query for unread articles
        $query = OrganizationInformation::with(['attachments'])
            ->where('status', 'published')
            ->whereNotIn('id', $viewedArticles ?: [0]);
        
        // Type filter (optional)
        if ($type = $request->query('type')) {
            if (in_array($type, self::TYPES)) {
                $query->where('type', $type);
            }
        }
        
        // Category filter (optional)
        if ($category = $request->query('category')) {
            if (in_array($category, self::CATEGORIES)) {
                $query->where('category', $category);
            }
        }
        
        // Search filter (optional)
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'ilike', "%{$search}%")
                  ->orWhere('content', 'ilike', "%{$search}%");
            });
        }
        
        // Add view count subquery
        $query->addSelect([
            'view_count' => ActivityLog::select(DB::raw('COUNT(DISTINCT user_id)'))
                ->whereColumn('auditable_id', 'national_information.id')
                ->where('auditable_type', OrganizationInformation::class)
                ->where('action', 'view')
        ])->withCasts(['view_count' => 'integer']);
        
        // Add unread status (always true for this query)
        $query->addSelect(DB::raw('true as is_unread'));
        
        // Sorting
        $sortBy = $request->query('sort_by', 'published_at');
        $sortOrder = $request->query('sort_order', 'desc');
        
        $sortableColumns = [
            'title', 'type', 'category', 'author', 'published_at', 
            'created_at', 'updated_at', 'view_count'
        ];
        
        if (in_array($sortBy, $sortableColumns)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('published_at', 'desc');
        }
        
        // Pagination
        $perPage = (int) $request->query('per_page', 20);
        
        // Create the ApiCollection and return its response
        $collection = ApiCollection::makeFromQuery($query, $perPage, $sortBy, $sortOrder);
        
        // Get the paginated data
        $paginatedData = $collection->toResponse($request)->getData(true);
        
        // Add temporary URLs to attachments
        if (isset($paginatedData['data'])) {
            foreach ($paginatedData['data'] as &$item) {
                // Add shareable URL
                $item['share_url'] = url("/national-information/{$item['public_uid']}");
                
                if (isset($item['attachments']) && is_array($item['attachments'])) {
                    foreach ($item['attachments'] as &$attachment) {
                        if (isset($attachment['file_path'])) {
                            try {
                                $attachment['file_url'] = Storage::disk('supabase')->temporaryUrl(
                                    $attachment['file_path'],
                                    now()->addMinutes(60) 
                                );
                            } catch (\Exception $e) {
                                Log::error('Failed to generate URL for attachment: ' . $attachment['file_path'], [
                                    'error' => $e->getMessage()
                                ]);
                                $attachment['file_url'] = null;
                            }
                        }
                    }
                }
            }
        }
        
        // Add metadata about unread articles
        $totalUnread = $query->count();
        $unreadByType = OrganizationInformation::where('status', 'published')
            ->whereNotIn('id', $viewedArticles ?: [0])
            ->select('type', DB::raw('count(*) as count'))
            ->groupBy('type')
            ->get()
            ->pluck('count', 'type');
        
        $paginatedData['meta']['total_unread'] = $totalUnread;
        $paginatedData['meta']['unread_by_type'] = $unreadByType;
        $paginatedData['meta']['filter_type'] = $type ?? 'all';
        
        return response()->json($paginatedData);
        
    } catch (\Exception $e) {
        Log::error('Failed to get unread articles: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Failed to fetch unread articles'
        ], 500);
    }
}
    }
