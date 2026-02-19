<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\LinkResource;
use App\Models\Link;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class LinkController extends Controller
{
    /**
     * Get links with filtering and pagination
     */
    public function index(Request $request)
    {
        try {
            $validated = $request->validate([
                'search' => 'nullable|string|max:255',
                'category' => 'nullable|string|max:255',
                'status' => 'nullable|string|in:active,inactive',
                'affiliate_id' => 'nullable|exists:affiliates,id',
                'is_public' => 'nullable|string|in:true,false,1,0',
                'per_page' => 'nullable',
                'sort_by' => 'nullable|string|in:title,display_order,created_at,category',
                'sort_order' => 'nullable|string|in:asc,desc',
            ]);

            $query = Link::query();

            // Apply filters
            $query->when(isset($validated['search']) && !empty(trim($validated['search'])), function ($q) use ($validated) {
                $q->where(function ($subQuery) use ($validated) {
                    $subQuery->where('title', 'LIKE', '%' . $validated['search'] . '%')
                             ->orWhere('description', 'LIKE', '%' . $validated['search'] . '%')
                             ->orWhere('url', 'LIKE', '%' . $validated['search'] . '%');
                });
            });

            $query->when(isset($validated['category']) && !empty(trim($validated['category'])), function ($q) use ($validated) {
                $q->where('category', $validated['category']);
            });

            $query->when(isset($validated['status']), function ($q) use ($validated) {
                $q->where('is_active', $validated['status'] === 'active');
            });

            $query->when(isset($validated['affiliate_id']), function ($q) use ($validated) {
                $q->where('affiliate_id', $validated['affiliate_id']);
            });

            $query->when(isset($validated['is_public']), function ($q) use ($validated) {
                $isPublic = in_array($validated['is_public'], ['true', '1']) ? true : false;
                $q->where('is_public', $isPublic);
            });

            // Default ordering
            $sortBy = $validated['sort_by'] ?? 'display_order';
            $sortOrder = $validated['sort_order'] ?? 'asc';

            // Per page handling
            $perPage = $validated['per_page'] ?? 25;
            if ($perPage === "All") {
                $perPage = 1000; // Large number to get all records
            } elseif (is_numeric($perPage)) {
                $perPage = (int) $perPage;
            } else {
                $perPage = 25;
            }

            // Use ApiCollection for consistent response format
            return ApiCollection::makeFromQuery($query, $perPage, $sortBy, $sortOrder)
                ->useResource(LinkResource::class);

        } catch (\Exception $e) {
            Log::error('Link index error: ' . $e->getMessage(), [
                'ip' => $request->ip(),
                'params' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve links',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get public links (for website integration)
     */
    public function public(Request $request)
    {
        try {
            $validated = $request->validate([
                'category' => 'nullable|string|max:255',
                'affiliate_id' => 'nullable|exists:affiliates,id',
                'per_page' => 'nullable',
                'sort_by' => 'nullable|string|in:title,display_order,created_at,category',
                'sort_order' => 'nullable|string|in:asc,desc',
            ]);

            $query = Link::where('is_public', true)
                        ->where('is_active', true);

            // Apply filters
            $query->when(isset($validated['category']) && !empty(trim($validated['category'])), function ($q) use ($validated) {
                $q->where('category', $validated['category']);
            });

            $query->when(isset($validated['affiliate_id']), function ($q) use ($validated) {
                $q->where('affiliate_id', $validated['affiliate_id']);
            });

            // Default ordering for public links
            $sortBy = $validated['sort_by'] ?? 'display_order';
            $sortOrder = $validated['sort_order'] ?? 'asc';

            // Per page handling
            $perPage = $validated['per_page'] ?? 50;
            if ($perPage === "All") {
                $perPage = 1000; // Large number to get all records
            } elseif (is_numeric($perPage)) {
                $perPage = (int) $perPage;
            } else {
                $perPage = 50;
            }

            // Use ApiCollection for consistent response format
            return ApiCollection::makeFromQuery($query, $perPage, $sortBy, $sortOrder)
                ->useResource(LinkResource::class);

        } catch (\Exception $e) {
            Log::error('Public links error: ' . $e->getMessage(), [
                'ip' => $request->ip(),
                'params' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve public links',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get link categories with counts
     */
    public function categories(Request $request)
    {
        try {
            $validated = $request->validate([
                'affiliate_id' => 'nullable|exists:affiliates,id',
                'is_public' => 'nullable|string|in:true,false,1,0',
            ]);

            $query = Link::query();

            // Apply filters if provided
            $query->when(isset($validated['affiliate_id']), function ($q) use ($validated) {
                $q->where('affiliate_id', $validated['affiliate_id']);
            });

            $query->when(isset($validated['is_public']), function ($q) use ($validated) {
                $isPublic = in_array($validated['is_public'], ['true', '1']) ? true : false;
                $q->where('is_public', $isPublic);
            });

            // Always show active links for categories
            $query->where('is_active', true);

            $categories = $query->selectRaw('category, COUNT(*) as total')
                ->groupBy('category')
                ->orderBy('category', 'asc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $categories,
            ]);

        } catch (\Exception $e) {
            Log::error('Link categories error: ' . $e->getMessage(), [
                'ip' => $request->ip(),
                'params' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch categories',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export links to CSV
     */
    public function export(Request $request)
    {
        try {
            $validated = $request->validate([
                'search' => 'nullable|string|max:255',
                'category' => 'nullable|string|max:255',
                'status' => 'nullable|string|in:active,inactive',
                'affiliate_id' => 'nullable|exists:affiliates,id',
                'is_public' => 'nullable|string|in:true,false,1,0',
            ]);

            $query = Link::query();

            // Apply filters
            $query->when(isset($validated['search']) && !empty(trim($validated['search'])), function ($q) use ($validated) {
                $q->where(function ($subQuery) use ($validated) {
                    $subQuery->where('title', 'LIKE', '%' . $validated['search'] . '%')
                             ->orWhere('description', 'LIKE', '%' . $validated['search'] . '%')
                             ->orWhere('url', 'LIKE', '%' . $validated['search'] . '%');
                });
            });

            $query->when(isset($validated['category']) && !empty(trim($validated['category'])), function ($q) use ($validated) {
                $q->where('category', $validated['category']);
            });

            $query->when(isset($validated['status']), function ($q) use ($validated) {
                $q->where('is_active', $validated['status'] === 'active');
            });

            $query->when(isset($validated['affiliate_id']), function ($q) use ($validated) {
                $q->where('affiliate_id', $validated['affiliate_id']);
            });

            $query->when(isset($validated['is_public']), function ($q) use ($validated) {
                $isPublic = in_array($validated['is_public'], ['true', '1']) ? true : false;
                $q->where('is_public', $isPublic);
            });

            $links = $query->orderBy('display_order', 'asc')
                          ->orderBy('title', 'asc')
                          ->get();

            // Generate CSV
            $csvData = [];
            
            // Add header
            $csvData[] = [
                'ID', 'Title', 'URL', 'Description', 'Category', 
                'Display Order', 'Status', 'Visibility', 'Affiliate ID', 
                'Created At', 'Updated At'
            ];

            // Add rows
            foreach ($links as $link) {
                $csvData[] = [
                    $link->id,
                    $link->title,
                    $link->url,
                    $link->description,
                    $link->category,
                    $link->display_order,
                    $link->is_active ? 'Active' : 'Inactive',
                    $link->is_public ? 'Public' : 'Private',
                    $link->affiliate_id,
                    $link->created_at->format('Y-m-d H:i:s'),
                    $link->updated_at->format('Y-m-d H:i:s'),
                ];
            }

            // Convert to CSV string
            $csvContent = '';
            foreach ($csvData as $row) {
                $csvContent .= implode(',', array_map(function ($item) {
                    // Escape quotes and wrap in quotes if contains comma or quotes
                    $item = str_replace('"', '""', $item ?? '');
                    if (strpos($item, ',') !== false || strpos($item, '"') !== false) {
                        return '"' . $item . '"';
                    }
                    return $item;
                }, $row)) . "\n";
            }

            $filename = 'links-export-' . date('Y-m-d') . '.csv';

            return response($csvContent)
                ->header('Content-Type', 'text/csv')
                ->header('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->header('Pragma', 'no-cache')
                ->header('Expires', '0');

        } catch (\Exception $e) {
            Log::error('Link export error: ' . $e->getMessage(), [
                'ip' => $request->ip(),
                'params' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to export links',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created link
     */
// In your store method, add logging to see what's being received
public function store(Request $request)
{
    try {
        // Log the incoming request data for debugging
        Log::info('Link creation request data:', $request->all());
        
        $validator = Validator::make($request->all(), [
            'affiliate_id' => ['nullable', 'exists:affiliates,id'],
            'title' => ['required', 'string', 'max:255'],
            'url' => ['required', 'string', 'url', 'max:500'],
            'description' => ['required', 'string', 'max:1000'],
            'category' => ['required', 'string', 'max:100'],
            'display_order' => ['required', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'is_public' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            Log::error('Link validation failed:', $validator->errors()->toArray());
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();
        
        // Log validated data
        Log::info('Validated data:', $validated);
        
        // Convert empty string affiliate_id to null
        if (isset($validated['affiliate_id']) && $validated['affiliate_id'] === '') {
            $validated['affiliate_id'] = null;
        }
        
        // Convert affiliate_id to integer if it's a string
        if (isset($validated['affiliate_id']) && is_string($validated['affiliate_id'])) {
            $validated['affiliate_id'] = (int) $validated['affiliate_id'];
        }
        
        // Set defaults
        $validated['is_active'] = $validated['is_active'] ?? true;
        $validated['is_public'] = $validated['is_public'] ?? false;
        
        // Convert boolean strings to actual booleans
        foreach (['is_active', 'is_public'] as $field) {
            if (isset($validated[$field])) {
                if (is_string($validated[$field])) {
                    $validated[$field] = filter_var($validated[$field], FILTER_VALIDATE_BOOLEAN);
                }
            }
        }

        Log::info('Creating link with data:', $validated);
        
        $link = Link::create($validated);

        Log::info('Link created successfully:', ['id' => $link->id]);

        return response()->json([
            'success' => true,
            'data' => new LinkResource($link),
            'message' => 'Link created successfully'
        ], 201);

    } catch (\Exception $e) {
        Log::error('Link creation failed: ' . $e->getMessage(), [
            'ip' => $request->ip(),
            'data' => $request->all(),
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Failed to create link',
            'error' => $e->getMessage()
        ], 500);
    }
}

    /**
     * Display the specified link
     */
    public function show($id)
    {
        try {
            $link = Link::findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => new LinkResource($link)
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Link not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Link show error: ' . $e->getMessage(), [
                'link_id' => $id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve link',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified link
     */
public function update(Request $request, Link $link)
{
    try {
        Log::info('Link update request data:', [
            'link_id' => $link->id,
            'request_data' => $request->all()
        ]);
        
        $validator = Validator::make($request->all(), [
            'affiliate_id' => ['nullable', 'exists:affiliates,id'],
            'title' => ['required', 'string', 'max:255'],
            'url' => ['required', 'string', 'url', 'max:500'],
            'description' => ['required', 'string', 'max:1000'],
            'category' => ['required', 'string', 'max:100'],
            'display_order' => ['required', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'is_public' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            Log::error('Link update validation failed:', $validator->errors()->toArray());
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();
        
        Log::info('Validated update data (before processing):', $validated);
        
        // Handle affiliate_id - convert empty string to null
        if (array_key_exists('affiliate_id', $validated)) {
            if ($validated['affiliate_id'] === '' || $validated['affiliate_id'] === 'null') {
                $validated['affiliate_id'] = null;
            } elseif (is_string($validated['affiliate_id'])) {
                $validated['affiliate_id'] = (int) $validated['affiliate_id'];
            }
        } else {
            // If affiliate_id is not in request, keep existing value
            $validated['affiliate_id'] = $link->affiliate_id;
        }
        
        // Ensure boolean fields are properly cast
        foreach (['is_active', 'is_public'] as $field) {
            if (array_key_exists($field, $validated)) {
                // Handle string booleans
                if (is_string($validated[$field])) {
                    $validated[$field] = filter_var($validated[$field], FILTER_VALIDATE_BOOLEAN);
                }
                // Ensure it's cast to integer (1/0) for database
                $validated[$field] = (bool) $validated[$field];
            } else {
                // If field not in request, keep existing value
                $validated[$field] = $link->$field;
            }
        }
        
        Log::info('Processed update data:', $validated);
        
        $link->update($validated);
        
        // Log the updated values
        Log::info('Link after update:', [
            'id' => $link->id,
            'is_public' => $link->is_public,
            'affiliate_id' => $link->affiliate_id,
            'is_active' => $link->is_active
        ]);

        return response()->json([
            'success' => true,
            'data' => new LinkResource($link),
            'message' => 'Link updated successfully'
        ], 200);

    } catch (\Exception $e) {
        Log::error('Link update failed: ' . $e->getMessage(), [
            'link_id' => $link->id,
            'ip' => $request->ip(),
            'data' => $request->all(),
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Failed to update link',
            'error' => $e->getMessage()
        ], 500);
    }
}
    /**
     * Remove the specified link
     */
    public function destroy($id)
    {
        try {
            $link = Link::findOrFail($id);
            $link->delete();

            return response()->json([
                'success' => true,
                'message' => 'Link deleted successfully'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Link not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Link deletion failed: ' . $e->getMessage(), [
                'link_id' => $id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete link',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}