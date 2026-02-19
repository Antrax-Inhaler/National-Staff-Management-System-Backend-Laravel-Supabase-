<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\OrganizationInformation;
use App\Models\OrganizationInformationAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InformationController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = OrganizationInformation::query();

            // 🔍 Advanced filtering
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'ILIKE', "%{$search}%")
                        ->orWhere('content', 'ILIKE', "%{$search}%");
                });
            }

            if ($request->filled('type')) {
                $query->where('type', $request->type);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            // Date filters
            if ($request->filled('published_before')) {
                $query->where('published_at', '<=', $request->published_before);
            }

            if ($request->filled('published_after')) {
                $query->where('published_at', '>=', $request->published_after);
            }

            // Ordering (default newest first)
            $query->orderBy('published_at', 'desc')
                ->orderBy('title', 'asc');

            // Pagination
            $perPage = $request->get('per_page', 25);
            $perPage = $perPage === 'All' ? null : min(max((int)$perPage, 1), 100);

            $items = $perPage ? $query->paginate($perPage) : $query->get();

            $response = [
                'data' => $perPage ? $items->items() : $items,
                'meta' => $perPage ? [
                    'current_page' => $items->currentPage(),
                    'last_page'    => $items->lastPage(),
                    'per_page'     => $items->perPage(),
                    'total'        => $items->total(),
                ] : [
                    'current_page' => 1,
                    'last_page'    => 1,
                    'per_page'     => $items->count(),
                    'total'        => $items->count(),
                ],
            ];

            return response()->json([
                'success' => true,
                'data'    => $response['data'],
                'meta'    => $response['meta'],
                'message' => 'Organization information retrieved successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('OrganizationInformation index error: ' . $e->getMessage(), [
                'ip'     => $request->ip(),
                'params' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve records',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // 📌 Show single record
    public function show($id)
    {
        $record = OrganizationInformation::find($id);

        if (!$record) {
            return response()->json([
                'success' => false,
                'message' => 'Record not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $record,
            'message' => 'Organization information retrieved successfully'
        ]);
    }

    // 📌 Create new information (removed role restriction)
    public function store(Request $request)
    {
        try {
            $user = $request->attributes->get('supabase_user');
            
            $validated = $request->validate([
                'type'    => 'required|in:announcement,policy,report,update',
                'title'   => 'required|string|max:255',
                'content' => 'nullable|string',
                'status'  => 'in:draft,published,archived',
                'published_at' => 'nullable|date',
            ]);

            $validated['status'] = $validated['status'] ?? 'draft'; // Default to draft
            $validated['published_at'] = $validated['status'] === 'published' 
                ? ($validated['published_at'] ?? now()) 
                : null;

            $record = OrganizationInformation::create($validated);

            // Handle attachments
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    if ($file->isValid()) {
                        $path = $file->store('national-information-attachments', 'public');
                        
                        OrganizationInformationAttachment::create([
                            'national_info_id' => $record->id,
                            'file_name' => $file->getClientOriginalName(),
                            'file_path' => $path,
                            'file_size' => $file->getSize(),
                        ]);
                    }
                }
            }

            // Activity log
            if ($user) {
                ActivityLog::create([
                    'user_id'        => $this->getUserId($user),
                    'action'         => 'create_national_info',
                    'auditable_type' => OrganizationInformation::class,
                    'auditable_id'   => $record->id,
                    'old_values'     => json_encode([]),
                    'new_values'     => json_encode($validated),
                    'ip_address'     => $request->ip(),
                    'user_agent'     => $request->userAgent()
                ]);
            }

            return response()->json([
                'success' => true,
                'data'    => $record->load('attachments'),
                'message' => 'Organization information created successfully'
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to create national information: ' . $e->getMessage(), [
                'request' => $request->all(),
                'user'    => $user ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create record',
                'error'   => env('APP_DEBUG') ? $e->getMessage() : 'An error occurred'
            ], 500);
        }
    }

    // 📌 Update information (removed role restriction)
    public function update(Request $request, $id)
    {
        $record = OrganizationInformation::find($id);
        if (!$record) {
            return response()->json([
                'success' => false,
                'message' => 'Record not found'
            ], 404);
        }

        $user = $request->attributes->get('supabase_user');
        
        $validated = $request->validate([
            'type'    => 'sometimes|in:announcement,policy,report,update',
            'title'   => 'sometimes|string|max:255',
            'content' => 'nullable|string',
            'status'  => 'in:draft,published,archived',
        ]);

        $oldValues = $record->toArray();
        $record->update($validated);

        // Activity log
        if ($user) {
            ActivityLog::create([
                'user_id'       => $this->getUserId($user),
                'action'        => 'update_national_info',
                'auditable_type' => OrganizationInformation::class,
                'auditable_id'  => $record->id,
                'old_values'    => json_encode($oldValues),
                'new_values'    => json_encode($validated),
                'ip_address'    => $request->ip(),
                'user_agent'    => $request->userAgent()
            ]);
        }

        return response()->json([
            'success' => true,
            'data'    => $record,
            'message' => 'Organization information updated successfully'
        ]);
    }

    // 📌 Delete information (removed role restriction)
    public function destroy(Request $request, $id)
    {
        $record = OrganizationInformation::find($id);
        if (!$record) {
            return response()->json([
                'success' => false,
                'message' => 'Record not found'
            ], 404);
        }

        $user = $request->attributes->get('supabase_user');
        
        $oldValues = $record->toArray();
        $record->delete();

        // Activity log
        if ($user) {
            ActivityLog::create([
                'user_id'       => $this->getUserId($user),
                'action'        => 'delete_national_info',
                'auditable_type' => OrganizationInformation::class,
                'auditable_id'  => $id,
                'old_values'    => json_encode($oldValues),
                'new_values'    => json_encode([]),
                'ip_address'    => $request->ip(),
                'user_agent'    => $request->userAgent()
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Organization information deleted successfully'
        ]);
    }

    // 📌 Get categories with counts
    public function categories()
    {
        try {
            $categories = OrganizationInformation::selectRaw('type, count(*) as total')
                ->groupBy('type')
                ->orderBy('type')
                ->get()
                ->map(function ($item) {
                    return [
                        'type' => ucfirst($item->type),
                        'total' => $item->total
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $categories,
                'message' => 'Categories retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('OrganizationInformation categories error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve categories',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // 📌 Get statistics
    public function stats(Request $request)
    {
        $stats = [
            'total' => OrganizationInformation::count(),
            'published' => OrganizationInformation::where('status', 'published')->count(),
            'draft' => OrganizationInformation::where('status', 'draft')->count(),
            'archived' => OrganizationInformation::where('status', 'archived')->count(),
            'announcements' => OrganizationInformation::where('type', 'announcement')->count(),
            'policies' => OrganizationInformation::where('type', 'policy')->count(),
            'reports' => OrganizationInformation::where('type', 'report')->count(),
            'updates' => OrganizationInformation::where('type', 'update')->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    // 📌 Bulk actions (removed role restriction)
    public function bulkActions(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'action' => 'required|in:delete,publish,archive,draft',
        ]);

        $user = $request->attributes->get('supabase_user');
        
        $query = OrganizationInformation::whereIn('id', $request->ids);

        switch ($request->action) {
            case 'delete':
                $query->delete();
                $message = 'Selected items deleted successfully';
                break;
            case 'publish':
                $query->update(['status' => 'published', 'published_at' => now()]);
                $message = 'Selected items published successfully';
                break;
            case 'archive':
                $query->update(['status' => 'archived']);
                $message = 'Selected items archived successfully';
                break;
            case 'draft':
                $query->update(['status' => 'draft']);
                $message = 'Selected items moved to draft';
                break;
        }

        // Log bulk action if user exists
        if ($user) {
            ActivityLog::create([
                'user_id' => $this->getUserId($user),
                'action' => 'bulk_' . $request->action . '_national_info',
                'auditable_type' => OrganizationInformation::class,
                'auditable_id' => 0,
                'old_values' => json_encode([]),
                'new_values' => json_encode(['ids' => $request->ids, 'action' => $request->action]),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => $message,
        ]);
    }

    // 🔑 Helper to get user ID (kept for activity logging)
    private function getUserId($user)
    {
        if (!$user || empty($user['sub'])) {
            return null;
        }
        
        $userModel = \App\Models\User::where('supabase_uid', $user['sub'])->first();
        return $userModel ? $userModel->id : null;
    }

    public function publicIndex(Request $request)
    {
        try {
            $query = OrganizationInformation::where('status', 'published');

            // 🔍 Advanced filtering
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'ILIKE', "%{$search}%")
                        ->orWhere('content', 'ILIKE', "%{$search}%");
                });
            }

            if ($request->filled('type')) {
                $query->where('type', $request->type);
            }

            // Date filters
            if ($request->filled('published_before')) {
                $query->where('published_at', '<=', $request->published_before);
            }

            if ($request->filled('published_after')) {
                $query->where('published_at', '>=', $request->published_after);
            }

            // Ordering (default newest first)
            $query->orderBy('published_at', 'desc')
                ->orderBy('title', 'asc');

            // Pagination
            $perPage = $request->get('per_page', 25);
            $perPage = $perPage === 'All' ? null : min(max((int)$perPage, 1), 100);

            $items = $perPage ? $query->paginate($perPage) : $query->get();

            $response = [
                'data' => $perPage ? $items->items() : $items,
                'meta' => $perPage ? [
                    'current_page' => $items->currentPage(),
                    'last_page'    => $items->lastPage(),
                    'per_page'     => $items->perPage(),
                    'total'        => $items->total(),
                ] : [
                    'current_page' => 1,
                    'last_page'    => 1,
                    'per_page'     => $items->count(),
                    'total'        => $items->count(),
                ],
            ];

            return response()->json([
                'success' => true,
                'data'    => $response['data'],
                'meta'    => $response['meta'],
                'message' => 'Public information retrieved successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('OrganizationInformation public index error: ' . $e->getMessage(), [
                'ip'     => $request->ip(),
                'params' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve public records',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function publicShow($id)
    {
        $record = OrganizationInformation::where('status', 'published')->find($id);

        if (!$record) {
            return response()->json([
                'success' => false,
                'message' => 'Record not found or not published'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $record,
            'message' => 'Public information retrieved successfully'
        ]);
    }
}