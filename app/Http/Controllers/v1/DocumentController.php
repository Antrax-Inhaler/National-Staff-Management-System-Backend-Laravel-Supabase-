<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\ActivityLog;
use App\Models\DocumentFolder;
use App\Models\Member;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class DocumentController extends Controller
{
    /**
     * Display a listing of documents with filters and search
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $member = Member::where("user_id", $user->id)->first();
            $affiliate_id = null;

            $query = Document::active()
                ->with(['affiliate', 'uploader', 'uploadLog', 'folder']);

            // 🔹 Search
            if ($request->filled('search')) {
                $useSimplified = $request->get('use_simplified_syntax', true);
                $this->applySearchSyntax($query, $request->search, $useSimplified);
            }

            // 🔹 Advanced Filters
            $this->applyAdvancedFilters($request, $query, $affiliate_id);

            // 🔹 FOLDER FILTER - ADD THIS!
            if ($request->filled('folder_id')) {
                $folderId = $request->get('folder_id');
                $query->where('folder_id', $folderId);
            }

            // 🔹 Repository Filtering
            if ($request->filled('repository')) {
                $this->applyRepositoryFilter($query, $request->repository);
            }

            // 🔹 Ordering
            $orderBy = $request->get('order_by', 'created_at');
            $orderDir = $request->get('order_dir', 'desc');
            $query->orderBy($orderBy, $orderDir);

            // 🔹 Pagination
            $perPage = $request->get('per_page', 25);
            if ($perPage === 'All') {
                $documents = $query->get();
            } else {
                $perPage = min(max((int)$perPage, 1), 100);
                $documents = $query->paginate($perPage);
            }

            // 🔹 Map documents with all fields + folder
            $data = collect($documents->items() ?? $documents)->map(function ($doc) {
                return [
                    'id' => $doc->id,
                    'title' => $doc->title,
                    'type' => $doc->type,
                    'category' => $doc->category,
                    'description' => $doc->description,
                    'file_name' => $doc->file_name,
                    'file_size' => $doc->file_size,
                    'affiliate' => $doc->affiliate,
                    'uploader' => $doc->uploader,
                    'uploaded_at' => optional($doc->uploadLog)->created_at,

                    // NEW FIELDS:
                    'expiration_date' => $doc->expiration_date,
                    'employer' => $doc->employer,
                    'cbc' => $doc->cbc,
                    'state' => $doc->state,
                    'effective_date' => $doc->effective_date,
                    'status' => $doc->status,
                    'database_source' => $doc->database_source,
                    'is_archived' => $doc->is_archived,
                    'keywords' => $doc->keywords,
                    'sub_type' => $doc->sub_type,
                    'year' => $doc->year,
                    'is_public' => $doc->is_public,

                    // 👇 Folder info
                    'folder' => $doc->folder ? [
                        'id' => $doc->folder->id,
                        'name' => $doc->folder->name,
                        'parent_id' => $doc->folder->parent_id,
                    ] : null,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
                'meta' => [
                    'current_page' => $documents->currentPage(),
                    'last_page' => $documents->lastPage(),
                    'per_page' => $documents->perPage(),
                    'total' => $documents->total(),
                    'search_applied' => $request->filled('search'),
                    'filters_applied' => $request->except('page', 'per_page')
                ],
                'message' => 'Documents retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Document index error: ' . $e->getMessage(), [
                'ip' => $request->ip(),
                'params' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve documents',
                'error' => $e->getMessage()
            ], 500);
        }
    }
        /**
 * Get Research Documents only - with complete functionality
 */
/**
 * Get Research Documents only - with complete functionality
 */
public function researchDocuments(Request $request): JsonResponse
{
    try {
        $user = Auth::user();
        $member = Member::where("user_id", $user->id)->first();
        $affiliate_id = null;

        $query = Document::active()
            ->with(['affiliate', 'uploader', 'uploadLog', 'folder'])
            ->where('category_group', 'research'); // 👈 RESEARCH ONLY

        // 🔹 Apply default status=active filter unless explicitly overridden
        if (!$request->filled('status')) {
            $query->where('status', 'active');
        }

        // 🔹 Search
        if ($request->filled('search')) {
            $useSimplified = $request->get('use_simplified_syntax', true);
            $this->applySearchSyntax($query, $request->search, $useSimplified);
        }

        // 🔹 Advanced Filters
        $this->applyAdvancedFilters($request, $query, $affiliate_id);

        // 🔹 FOLDER FILTER
        if ($request->filled('folder_id')) {
            $folderId = $request->get('folder_id');
            $query->where('folder_id', $folderId);
        }

        // 🔹 Repository Filtering
        if ($request->filled('repository')) {
            $this->applyRepositoryFilter($query, $request->repository);
        }

        // 🔹 Ordering
        $orderBy = $request->get('order_by', 'created_at');
        $orderDir = $request->get('order_dir', 'desc');
        $query->orderBy($orderBy, $orderDir);

        // 🔹 Pagination
        $perPage = $request->get('per_page', 25);
        if ($perPage === 'All') {
            $documents = $query->get();
        } else {
            $perPage = min(max((int)$perPage, 1), 100);
            $documents = $query->paginate($perPage);
        }

        // 🔹 Map documents with all fields + folder + category_group
        $data = collect($documents->items() ?? $documents)->map(function ($doc) {
            return [
                'id' => $doc->id,
                'title' => $doc->title,
                'type' => $doc->type,
                'category_group' => $doc->category_group, // 👈 INCLUDED
                'category' => $doc->category,
                'description' => $doc->description,
                'file_name' => $doc->file_name,
                'file_size' => $doc->file_size,
                'affiliate' => $doc->affiliate,
                'uploader' => $doc->uploader,
                'uploaded_at' => optional($doc->uploadLog)->created_at,

                // NEW FIELDS:
                'expiration_date' => $doc->expiration_date,
                'employer' => $doc->employer,
                'cbc' => $doc->cbc,
                'state' => $doc->state,
                'effective_date' => $doc->effective_date,
                'status' => $doc->status,
                'database_source' => $doc->database_source,
                'is_archived' => $doc->is_archived,
                'keywords' => $doc->keywords,
                'sub_type' => $doc->sub_type,
                'year' => $doc->year,
                'is_public' => $doc->is_public,

                // 👇 Folder info
                'folder' => $doc->folder ? [
                    'id' => $doc->folder->id,
                    'name' => $doc->folder->name,
                    'parent_id' => $doc->folder->parent_id,
                ] : null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $documents->currentPage(),
                'last_page' => $documents->lastPage(),
                'per_page' => $documents->perPage(),
                'total' => $documents->total(),
                'search_applied' => $request->filled('search'),
                'filters_applied' => $request->except('page', 'per_page'),
                'category_group' => 'research', // 👈 SPECIFY GROUP
                'default_status_filter' => !$request->filled('status') ? 'active' : 'custom' // 👈 INDICATE DEFAULT FILTER WAS APPLIED
            ],
            'message' => 'Research documents retrieved successfully'
        ]);
    } catch (\Exception $e) {
        Log::error('Research documents error: ' . $e->getMessage(), [
            'ip' => $request->ip(),
            'params' => $request->all()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Failed to retrieve research documents',
            'error' => $e->getMessage()
        ], 500);
    }
}

public function governanceDocuments(Request $request): JsonResponse
{
    try {
        $user = Auth::user();
        $member = Member::where("user_id", $user->id)->first();
        $affiliate_id = null;

        $query = Document::active()
            ->with(['affiliate', 'uploader', 'uploadLog', 'folder'])
            ->where('category_group', 'governance'); // 👈 GOVERNANCE ONLY

        // 🔹 Apply default status=active filter unless explicitly overridden
        if (!$request->filled('status')) {
            $query->where('status', 'active');
        }

        // 🔹 Search
        if ($request->filled('search')) {
            $useSimplified = $request->get('use_simplified_syntax', true);
            $this->applySearchSyntax($query, $request->search, $useSimplified);
        }

        // 🔹 Advanced Filters
        $this->applyAdvancedFilters($request, $query, $affiliate_id);

        // 🔹 FOLDER FILTER
        if ($request->filled('folder_id')) {
            $folderId = $request->get('folder_id');
            $query->where('folder_id', $folderId);
        }

        // 🔹 Repository Filtering
        if ($request->filled('repository')) {
            $this->applyRepositoryFilter($query, $request->repository);
        }

        // 🔹 Ordering
        $orderBy = $request->get('order_by', 'created_at');
        $orderDir = $request->get('order_dir', 'desc');
        $query->orderBy($orderBy, $orderDir);

        // 🔹 Pagination
        $perPage = $request->get('per_page', 25);
        if ($perPage === 'All') {
            $documents = $query->get();
        } else {
            $perPage = min(max((int)$perPage, 1), 100);
            $documents = $query->paginate($perPage);
        }

        // 🔹 Map documents with all fields + folder + category_group
        $data = collect($documents->items() ?? $documents)->map(function ($doc) {
            return [
                'id' => $doc->id,
                'title' => $doc->title,
                'type' => $doc->type,
                'category_group' => $doc->category_group, // 👈 INCLUDED
                'category' => $doc->category,
                'description' => $doc->description,
                'file_name' => $doc->file_name,
                'file_size' => $doc->file_size,
                'affiliate' => $doc->affiliate,
                'uploader' => $doc->uploader,
                'uploaded_at' => optional($doc->uploadLog)->created_at,

                // NEW FIELDS:
                'expiration_date' => $doc->expiration_date,
                'employer' => $doc->employer,
                'cbc' => $doc->cbc,
                'state' => $doc->state,
                'effective_date' => $doc->effective_date,
                'status' => $doc->status,
                'database_source' => $doc->database_source,
                'is_archived' => $doc->is_archived,
                'keywords' => $doc->keywords,
                'sub_type' => $doc->sub_type,
                'year' => $doc->year,
                'is_public' => $doc->is_public,

                // 👇 Folder info
                'folder' => $doc->folder ? [
                    'id' => $doc->folder->id,
                    'name' => $doc->folder->name,
                    'parent_id' => $doc->folder->parent_id,
                ] : null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $documents->currentPage(),
                'last_page' => $documents->lastPage(),
                'per_page' => $documents->perPage(),
                'total' => $documents->total(),
                'search_applied' => $request->filled('search'),
                'filters_applied' => $request->except('page', 'per_page'),
                'category_group' => 'governance', // 👈 SPECIFY GROUP
                'default_status_filter' => !$request->filled('status') ? 'active' : 'custom' // 👈 INDICATE DEFAULT FILTER WAS APPLIED
            ],
            'message' => 'Governance documents retrieved successfully'
        ]);
    } catch (\Exception $e) {
        Log::error('Governance documents error: ' . $e->getMessage(), [
            'ip' => $request->ip(),
            'params' => $request->all()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Failed to retrieve governance documents',
            'error' => $e->getMessage()
        ], 500);
    }
}


    /**
     * Get documents by repository type
     */
    public function byRepository(Request $request, $repository): JsonResponse
    {
        try {
            $user = $request->attributes->get('supabase_user');
            $userModel = \App\Models\User::where('supabase_uid', $user['sub'])->first();

            if (!$userModel) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            $userAffiliateId = $this->getUserAffiliateId($userModel);
            $isOrganization = $this->isOrganizationUser($userModel);

            $query = Document::with(['affiliate', 'uploader', 'uploadLog', 'folder']); // 👈 include folder

            // Access control
            if (!$isOrganization && $userAffiliateId) {
                $query->where(function ($q) use ($userAffiliateId) {
                    $q->where('affiliate_id', $userAffiliateId)
                        ->orWhereNull('affiliate_id');
                });
            }

            // Apply repository filter
            $this->applyRepositoryFilter($query, $repository);

            // Search within repository
            if ($request->filled('search')) {
                $query->fullTextSearch($request->search);
            }

            $documents = $query->orderBy('created_at', 'desc')->paginate(25);

            $data = collect($documents->items())->map(function ($doc) {
                return [
                    'id' => $doc->id,
                    'title' => $doc->title,
                    'type' => $doc->type,
                    'database_source' => $doc->database_source,
                    'affiliate' => $doc->affiliate,
                    'employer' => $doc->employer,
                    'state' => $doc->state,
                    'expiration_date' => $doc->expiration_date,
                    'uploaded_at' => optional($doc->uploadLog)->created_at,

                    // 👇 Folder info
                    'folder' => $doc->folder ? [
                        'id' => $doc->folder->id,
                        'name' => $doc->folder->name,
                        'parent_id' => $doc->folder->parent_id,
                    ] : null,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
                'meta' => [
                    'current_page' => $documents->currentPage(),
                    'last_page' => $documents->lastPage(),
                    'per_page' => $documents->perPage(),
                    'total' => $documents->total(),
                    'repository' => $repository
                ],
                'message' => 'Repository documents retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Repository documents error: ' . $e->getMessage(), [
                'repository' => $repository,
                'ip' => $request->ip()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve repository documents',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Advanced search with complex filters
     */
    public function advancedSearch(Request $request): JsonResponse
    {
        try {
            $user = $request->attributes->get('supabase_user');
            $userModel = \App\Models\User::where('supabase_uid', $user['sub'])->first();

            if (!$userModel) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            $userAffiliateId = $this->getUserAffiliateId($userModel);
            $isOrganization = $this->isOrganizationUser($userModel);

            $query = Document::active()->with(['affiliate', 'uploader']);

            // Access control
            if (!$isOrganization && $userAffiliateId) {
                $query->where(function ($q) use ($userAffiliateId) {
                    $q->where('affiliate_id', $userAffiliateId)
                        ->orWhereNull('affiliate_id');
                });
            }

            // Apply all advanced filters
            $this->applyAdvancedFilters($query, $request, $isOrganization);

            // Boolean search logic
            if ($request->filled('boolean_search')) {
                $this->applyBooleanSearch($query, $request->boolean_search);
            }

            $documents = $query->orderBy('created_at', 'desc')->paginate(25);

            return response()->json([
                'success' => true,
                'data' => $documents->items(),
                'meta' => [
                    'current_page' => $documents->currentPage(),
                    'last_page' => $documents->lastPage(),
                    'per_page' => $documents->perPage(),
                    'total' => $documents->total(),
                ],
                'message' => 'Advanced search completed successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Advanced search error: ' . $e->getMessage(), [
                'ip' => $request->ip(),
                'params' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Advanced search failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get expiring contracts
     */
    public function expiringContracts(Request $request): JsonResponse
    {
        try {
            $user = $request->attributes->get('supabase_user');
            $userModel = \App\Models\User::where('supabase_uid', $user['sub'])->first();

            if (!$userModel) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            $userAffiliateId = $this->getUserAffiliateId($userModel);
            $isOrganization = $this->isOrganizationUser($userModel);

            $threshold = $request->get('days', 90); // Default 90 days
            $thresholdDate = now()->addDays($threshold);

            $query = Document::where('type', 'contract')
                ->where('expiration_date', '<=', $thresholdDate)
                ->where('status', 'active')
                ->with(['affiliate', 'uploader']);

            // Access control
            if (!$isOrganization && $userAffiliateId) {
                $query->where('affiliate_id', $userAffiliateId);
            }

            $expiringContracts = $query->orderBy('expiration_date', 'asc')->get();

            return response()->json([
                'success' => true,
                'data' => $expiringContracts,
                'meta' => [
                    'threshold_days' => $threshold,
                    'threshold_date' => $thresholdDate->toDateString(),
                    'total' => $expiringContracts->count()
                ],
                'message' => 'Expiring contracts retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Expiring contracts error: ' . $e->getMessage(), [
                'ip' => $request->ip()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve expiring contracts',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified document
     */
    public function show(Request $request, $id): JsonResponse
    {
        try {
            $user = $request->attributes->get('supabase_user');
            $userModel = \App\Models\User::where('supabase_uid', $user['sub'])->first();

            if (!$userModel) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            $document = Document::with(['affiliate', 'uploader'])->find($id);

            if (!$document) {
                return response()->json([
                    'success' => false,
                    'message' => 'Document not found'
                ], 404);
            }

            // Check access permissions
            if (!$this->canAccessDocument($userModel, $document)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied to this document'
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => $document,
                'message' => 'Document retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Document show error: ' . $e->getMessage(), [
                'document_id' => $id,
                'ip' => $request->ip()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve document',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created document
     */
public function store(Request $request): JsonResponse
{
    try {
        // ✅ Validate with new fields (including folder_id)
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'required|in:contract,arbitration,mou,bylaws,research,general',
            'category' => 'nullable|string|max:255',
            'category_group' => 'required|in:research,governance',
            'description' => 'nullable|string',
            'affiliate_id' => 'nullable|exists:affiliates,id',
            'folder_id' => 'nullable|exists:document_folders,id', // 👈 added
            'expiration_date' => 'nullable|date|after:today',
            'employer' => 'nullable|string|max:255',
            'cbc' => 'nullable|string|max:255',
            'state' => 'nullable|string',
            'effective_date' => 'nullable|date',
            'status' => 'nullable|in:active,expired,negotiation,draft',
            'database_source' => 'required|in:contracts,arbitrations,mous,research_collection,general',
            'is_archived' => 'nullable',
            'keywords' => 'nullable|string',
            'sub_type' => 'nullable|string|max:255',
            'year' => 'nullable|integer|min:1900|max:' . (date('Y') + 5),
            'is_public' => 'nullable',
            'file' => 'required|file|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt|max:10240',
        ]);

        Log::info('Starting document upload...', [
            'ip' => $request->ip(),
            'supabase_user' => $request->attributes->get('supabase_user'),
            'has_file' => $request->hasFile('file'),
            'request_params' => $request->except('file'),
        ]);

        $file = $request->file('file');
        $fileName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
            . '_' . time() . '.' . $file->getClientOriginalExtension();

        // Upload to Supabase
        $filePath = $file->storeAs('documents/affiliate', $fileName, 'supabase');
        Log::info('File uploaded', ['filePath' => $filePath]);

        // Create document with all fields
        $document = new Document();
        $document->title = $validated['title'];
        $document->type = $validated['type'];
        $document->category_group = $validated['category_group'];
        $document->category = $validated['category'] ?? null;
        $document->description = $validated['description'] ?? null;
        $document->affiliate_id = $validated['affiliate_id'] ?? $this->getUserAffiliateId(Auth::user());
        $document->folder_id = $validated['folder_id'] ?? null; // 👈 save folder_id
        $document->file_name = $file->getClientOriginalName();
        $document->file_path = $filePath;
        $document->file_size = $file->getSize();

        // Set extra fields
        $document->expiration_date = $validated['expiration_date'] ?? null;
        $document->employer = $validated['employer'] ?? null;
        $document->cbc = $validated['cbc'] ?? null;
        $document->state = $validated['state'] ?? null;
        $document->effective_date = $validated['effective_date'] ?? null;
        $document->status = $validated['status'] ?? 'active';
        $document->database_source = $validated['database_source'];
        $document->keywords = $validated['keywords'] ?? null;
        $document->sub_type = $validated['sub_type'] ?? null;
        $document->year = $validated['year'] ?? null;

        // ✅ Normalize booleans
        $isPublic = filter_var($validated['is_public'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $isArchivedInput = filter_var($validated['is_archived'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $document->is_public = $isPublic;

        if ($document->database_source === 'contracts' && $document->type === 'contract') {
            $document->is_archived = $isArchivedInput;
        } else {
            $document->is_archived = false;
        }

        // Extract text if PDF
        if ($document->isPdf()) {
            try {
                $realPath = $file->getRealPath();
                $extractedText = $document->extractTextFromPdf($realPath);
                $document->content_extract = $extractedText;
            } catch (\Throwable $e) {
                Log::error('PDF extraction failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        $document->save();

        // 🔥 Log activity
        ActivityLog::create([
            'user_id' => Auth::id(),
            'action' => 'document_uploaded',
            'auditable_type' => Document::class,
            'auditable_id' => $document->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'new_values' => $document->toArray()
        ]);

        return response()->json([
            'success' => true,
            'data' => $document->load(['affiliate', 'uploader', 'folder']), // 👈 also return folder
            'message' => 'Document uploaded successfully'
        ], 201);
        
    } catch (\Illuminate\Validation\ValidationException $e) {
        Log::error('Validation failed in document upload', [
            'errors' => $e->errors(),
            'request' => $request->except('file')
        ]);
        
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $e->errors()
        ], 422);
    } catch (\Throwable $e) {
        Log::error('Document store error: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Failed to upload document',
            'error' => $e->getMessage()
        ], 500);
    }
}
    /**
     * Update the specified document
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'id' => 'required|exists:documents,id',
            'affiliate_id' => 'sometimes|exists:affiliates,id',
            'title' => 'sometimes|string|max:255',
            'type' => 'sometimes|in:contract,arbitration,mou,bylaws,research,general',
            'category' => 'nullable|string|max:255',
            'category_group' => 'sometimes|in:research,governance',
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
            // NEW FIELDS:
            'expiration_date' => 'nullable|date',
            'employer' => 'nullable|string|max:255',
            'cbc' => 'nullable|string|max:255',
            'state' => 'nullable|string',
            'effective_date' => 'nullable|date',
            'status' => 'nullable|in:active,expired,negotiation,draft',
            'database_source' => 'sometimes|in:contracts,arbitrations,mous,research_collection,general',
            'is_archived' => 'boolean',
            'keywords' => 'nullable|string',
            'sub_type' => 'nullable|string|max:255',
            'year' => 'nullable|integer|min:1900|max:' . (date('Y') + 5),
            'is_public' => 'boolean',
            'folder_id' => 'nullable|exists:document_folders,id', // 👈 added
            'file' => 'nullable|file|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt|max:10240',
        ]);

        try {

            $document = Document::findOrFail($validated['id']);

            // Handle new file upload
            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $fileName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
                    . '_' . time() . '.' . $file->getClientOriginalExtension();

                // Upload sa Supabase
                $filePath = $file->storeAs('documents', $fileName, 'supabase');
                Log::info('Updated file uploaded', ['filePath' => $filePath]);

                $document->file_name = $file->getClientOriginalName();
                $document->file_path = $filePath;
                $document->file_size = $file->getSize();

                // If PDF → re-extract text
                if ($document->isPdf()) {
                    try {
                        $realPath = $file->getRealPath();
                        Log::info('Re-extracting text from updated PDF', ['path' => $realPath]);

                        $extractedText = $document->extractTextFromPdf($realPath);

                        Log::info('PDF re-extraction result', [
                            'length' => strlen($extractedText ?? ''),
                            'preview' => substr($extractedText ?? '', 0, 200),
                        ]);

                        $document->content_extract = $extractedText ?: null;

                        if (!empty($extractedText)) {
                            $document->search_vector = DB::raw("to_tsvector('english', ?)", [$extractedText]);
                        }
                    } catch (\Throwable $e) {
                        Log::error('PDF re-extraction failed', [
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);
                    }
                }
            }

            // ✅ Update other fields (including folder_id)
            $document->update($validated);

            Log::info('Document updated successfully', [
                'document_id' => $document->id,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => true,
                'data' => $document->load(['affiliate', 'uploader', 'folder']), // 👈 include folder
                'message' => 'Document updated successfully'
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Document update error: ' . $e->getMessage(), [
                'document_id' => $validated['id'],
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update document',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified document
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        try {
            $document = Document::find($id);

            if (!$document) {
                return response()->json([
                    'success' => false,
                    'message' => 'Document not found'
                ], 404);
            }

            // Delete file from storage
            Storage::disk('supabase')->delete($document->file_path);

            $document->delete();

            Log::info('Document deleted successfully', [
                'document_id' => $id,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Document deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Document delete error: ' . $e->getMessage(), [
                'document_id' => $id,
                'user_id' => Auth::id() ?? null
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete document',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Download the specified document
     */
    public function download(Request $request, $id): JsonResponse
    {
        try {
            $user = $request->attributes->get('supabase_user');
            $userModel = \App\Models\User::where('supabase_uid', $user['sub'])->first();

            if (!$userModel) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            $document = Document::find($id);

            if (!$document) {
                return response()->json([
                    'success' => false,
                    'message' => 'Document not found'
                ], 404);
            }

            // Check access permissions
            if (!$this->canAccessDocument($userModel, $document)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied to this document'
                ], 403);
            }

            // Generate signed URL for download (Supabase Storage)
            $signedUrl = Storage::disk('supabase')->temporaryUrl(
                $document->file_path,
                now()->addMinutes(30)
            );

            // Log download activity
            Log::info('Document download initiated', [
                'document_id' => $document->id,
                'user_id' => $userModel->id
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'download_url' => $signedUrl,
                    'file_name' => $document->file_name,
                    'expires_at' => now()->addMinutes(30)->toISOString()
                ],
                'message' => 'Download URL generated successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Document download error: ' . $e->getMessage(), [
                'document_id' => $id,
                'user_id' => $userModel->id ?? null
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate download URL',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Helper methods for filtering
     */
    public function researchStats(Request $request): JsonResponse
{
    try {
        $thirtyDaysAgo = Carbon::now()->subDays(30);

        $stats = [
            'total_documents' => Document::where('category_group', 'research')
                ->where('is_archived', false)
                ->count(),

            'active_contracts' => Document::where('category_group', 'research')
                ->where('type', 'contract')
                ->where('status', 'active')
                ->where('is_archived', false)
                ->count(),

            'expiring_soon' => Document::where('category_group', 'research')
                ->where('type', 'contract')
                ->where('status', 'active')
                ->where('is_archived', false)
                ->whereNotNull('expiration_date')
                ->where('expiration_date', '>=', now())
                ->where('expiration_date', '<=', now()->addDays(90))
                ->count(),

            'expired_contracts' => Document::where('category_group', 'research')
                ->where('type', 'contract')
                ->where('status', 'expired')
                ->where('is_archived', false)
                ->count(),

            'recent_uploads' => Document::where('category_group', 'research')
                ->where('is_archived', false)
                ->where('created_at', '>=', $thirtyDaysAgo)
                ->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
            'message' => 'Research documents statistics retrieved successfully'
        ]);
    } catch (\Exception $e) {
        Log::error('Research stats error: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Failed to retrieve research statistics'
        ], 500);
    }
}
public function governanceStats(Request $request): JsonResponse
{
    try {
        $thirtyDaysAgo = Carbon::now()->subDays(30);

        $stats = [
            'total_documents' => Document::where('category_group', 'governance')
                ->where('is_archived', false)
                ->count(),

            'active_contracts' => Document::where('category_group', 'governance')
                ->where('type', 'contract')
                ->where('status', 'active')
                ->where('is_archived', false)
                ->count(),

            'expiring_soon' => Document::where('category_group', 'governance')
                ->where('type', 'contract')
                ->where('status', 'active')
                ->where('is_archived', false)
                ->whereNotNull('expiration_date')
                ->where('expiration_date', '>=', now())
                ->where('expiration_date', '<=', now()->addDays(90))
                ->count(),

            'expired_contracts' => Document::where('category_group', 'governance')
                ->where('type', 'contract')
                ->where('status', 'expired')
                ->where('is_archived', false)
                ->count(),

            'recent_uploads' => Document::where('category_group', 'governance')
                ->where('is_archived', false)
                ->where('created_at', '>=', $thirtyDaysAgo)
                ->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
            'message' => 'Governance documents statistics retrieved successfully'
        ]);
    } catch (\Exception $e) {
        Log::error('Governance stats error: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Failed to retrieve governance statistics'
        ], 500);
    }
}


    private function applyAdvancedFilters(Request $request, $query, $affiliate_id = null): void
    {
         if ($request->filled('category_group')) {
        $query->where('category_group', $request->category_group);
    }
        if ($request->filled('affiliate')) {
            $query->whereHas('affiliate', function ($q) use ($request) {
                $q->where('name', 'ilike', '%' . $request->affiliate . '%');
            });
        }

        if ($request->filled('title')) {
            $query->where('title', 'ilike', '%' . $request->title . '%');
        }

        // Search syntax type
        if ($request->filled('search_syntax')) {
            // This would control simplified vs precise search
        }
        // State filter
        if ($request->filled('state')) {
            $query->where('state', $request->state);
        }

        // Employer filter
        if ($request->filled('employer')) {
            $query->where('employer', 'ilike', '%' . $request->employer . '%');
        }

        // CBC filter
        if ($request->filled('cbc')) {
            $query->where('cbc', 'ilike', '%' . $request->cbc . '%');
        }

        // Contract expiration date range
        if ($request->filled('contract_expiration_date_from')) {
            $query->where('expiration_date', '>=', $request->contract_expiration_date_from);
        }

        if ($request->filled('contract_expiration_date_to')) {
            $query->where('expiration_date', '<=', $request->contract_expiration_date_to);
        }

        // Status filter
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Year filter
        if ($request->filled('year')) {
            $query->where('year', $request->year);
        }

        // Database source filter
        if ($request->filled('database_source')) {
            $query->where('database_source', $request->database_source);
        }

        // Archive status filter
        if ($request->filled('is_archived')) {
            $query->where('is_archived', $request->boolean('is_archived'));
        }

        // Affiliate Filter
        if ($affiliate_id) {
            $query->where('affiliate_id', $affiliate_id);
        }

        // Public documents filter
        if ($request->filled('is_public')) {
            $query->where('is_public', $request->boolean('is_public'));
        }
    }

    private function applyRepositoryFilter($query, $repository): void
    {
        switch ($repository) {
            case 'contracts':
                $query->where('database_source', 'contracts')
                    ->where('is_archived', false);
                break;
            case 'contracts_archive':
                $query->where('database_source', 'contracts')
                    ->where('is_archived', true);
                break;
            case 'arbitrations':
                $query->where('database_source', 'arbitrations');
                break;
            case 'mous':
                $query->where('database_source', 'mous');
                break;
            case 'research':
                $query->where('database_source', 'research_collection');
                break;
        }
    }

    private function applyBooleanSearch($query, $searchString): void
    {
        // Basic boolean search implementation
        // You can enhance this with more complex parsing
        $terms = preg_split('/\s+(and|or|not)\s+/i', $searchString, -1, PREG_SPLIT_DELIM_CAPTURE);

        // Simple implementation - you might want to use a proper search package
        $query->where(function ($q) use ($terms) {
            foreach ($terms as $term) {
                if (strtolower($term) !== 'and' && strtolower($term) !== 'or' && strtolower($term) !== 'not') {
                    $q->orWhere('content_extract', 'ilike', '%' . $term . '%')
                        ->orWhere('title', 'ilike', '%' . $term . '%')
                        ->orWhere('description', 'ilike', '%' . $term . '%');
                }
            }
        });
    }

    /**
     * Helper methods for permission checking
     */
    private function getUserAffiliateId($userModel): ?int
    {
        $member = \App\Models\Member::where('user_id', $userModel->id)->first();
        return $member->affiliate_id ?? null;
    }

    private function isOrganizationUser($userModel): bool
    {
        $nationalRoles = ['Organization Administrator', 'ORG Executive Committee', 'ORG Research Committee'];

        return \App\Models\MemberOrganizationRole::whereHas('member', function ($query) use ($userModel) {
            $query->where('user_id', $userModel->id);
        })
            ->whereHas('role', function ($query) use ($nationalRoles) {
                $query->whereIn('name', $nationalRoles);
            })
            ->exists();
    }

    private function isAffiliateLeader($userModel, $affiliateId): bool
    {
        // Check if user is an officer in the affiliate
        return \App\Models\AffiliateOfficer::whereHas('member', function ($query) use ($userModel) {
            $query->where('user_id', $userModel->id);
        })
            ->where('affiliate_id', $affiliateId)
            ->where('is_vacant', false)
            ->exists();
    }

    private function canAccessDocument($userModel, Document $document): bool
    {
        if ($this->isOrganizationUser($userModel)) {
            return true; // Organization admins can access all documents
        }

        $userAffiliateId = $this->getUserAffiliateId($userModel);

        // Users can access national resources (null affiliate_id) or their affiliate's documents
        return $document->affiliate_id === null || $document->affiliate_id === $userAffiliateId;
    }

    private function canUploadDocument($userModel, Request $request): bool
    {
        if ($this->isOrganizationUser($userModel)) {
            return true; // Organization admins can upload anywhere
        }

        $requestAffiliateId = $request->input('affiliate_id');
        $userAffiliateId = $this->getUserAffiliateId($userModel);

        // Affiliate leaders can upload to their affiliate
        if ($requestAffiliateId && $requestAffiliateId === $userAffiliateId) {
            return $this->isAffiliateLeader($userModel, $userAffiliateId);
        }

        // Members can only upload to their affiliate (if leaders)
        return $this->isAffiliateLeader($userModel, $userAffiliateId);
    }

    private function canModifyDocument($userModel, Document $document): bool
    {
        if ($this->isOrganizationUser($userModel)) {
            return true; // Organization admins can modify any document
        }

        // Only affiliate leaders can modify their affiliate's documents
        if ($document->affiliate_id) {
            return $this->isAffiliateLeader($userModel, $document->affiliate_id);
        }

        // Only national admins can modify national resources
        return false;
    }
    private function applySearchSyntax($query, $searchString, $useSimplified = true): void
    {
        if ($useSimplified) {
            $this->applySimplifiedSearch($query, $searchString);
        } else {
            $this->applyPreciseSearch($query, $searchString);
        }
    }
    private function applySimplifiedSearch($query, $searchString): void
    {
        // Spaces = OR operation
        $terms = preg_split('/\s+/', $searchString);

        $query->where(function ($q) use ($terms) {
            foreach ($terms as $term) {
                if (str_starts_with($term, '+')) {
                    // Required term
                    $cleanTerm = substr($term, 1);
                    $q->where('content_extract', 'ilike', '%' . $cleanTerm . '%')
                        ->orWhere('title', 'ilike', '%' . $cleanTerm . '%');
                } elseif (str_starts_with($term, '-')) {
                    // Excluded term
                    $cleanTerm = substr( $term, 1);
                    $q->whereNot(function ($subQ) use ($cleanTerm) {
                        $subQ->where('content_extract', 'ilike', '%' . $cleanTerm . '%')
                            ->orWhere('title', 'ilike', '%' . $cleanTerm . '%');
                    });
                } else {
                    // Optional term (higher scoring when both match)
                    $q->orWhere('content_extract', 'ilike', '%' . $term . '%')
                        ->orWhere('title', 'ilike', '%' . $term . '%')
                        ->orWhere('description', 'ilike', '%' . $term . '%');
                }
            }
        });
    }
    private function applyPreciseSearch($query, $searchString): void
    {
        // Handle exact phrases in quotes
        preg_match_all('/"([^"]+)"/', $searchString, $phraseMatches);
        $phrases = $phraseMatches[1];

        // Remove phrases from search string to get individual terms
        $remainingString = preg_replace('/"([^"]+)"/', '', $searchString);
        $terms = array_filter(preg_split('/\s+/', $remainingString));

        $query->where(function ($q) use ($phrases, $terms) {
            // Exact phrase matching
            foreach ($phrases as $phrase) {
                $q->where('content_extract', 'ilike', '%' . $phrase . '%');
            }

            // Boolean operators
            $booleanMode = true;
            foreach ($terms as $term) {
                $lowerTerm = strtolower($term);

                if (in_array($lowerTerm, ['and', 'or', 'not', 'near'])) {
                    $booleanMode = $lowerTerm;
                    continue;
                }

                switch ($booleanMode) {
                    case 'and':
                        $q->where('content_extract', 'ilike', '%' . $term . '%');
                        break;
                    case 'or':
                        $q->orWhere('content_extract', 'ilike', '%' . $term . '%');
                        break;
                    case 'not':
                        $q->whereNot('content_extract', 'ilike', '%' . $term . '%');
                        break;
                    case 'near':
                        // Proximity search - basic implementation
                        $q->where('content_extract', 'ilike', '%' . $term . '%');
                        break;
                    default:
                        $q->where('content_extract', 'ilike', '%' . $term . '%');
                }
            }
        });
    }
public function getDatabaseStats(): JsonResponse
{
    $stats = [
        'mous' => Document::where('database_source', 'mous')->count(),
        'arbitrations' => Document::where('database_source', 'arbitrations')->count(),
        'contracts' => Document::where('database_source', 'contracts')->where('is_archived', false)->count(),
        'contracts_archive' => Document::where('database_source', 'contracts')->where('is_archived', true)->count(),
        'research_collection' => Document::where('database_source', 'research_collection')->count(),
        // NEW: Category Group Stats
        'research_documents' => Document::where('category_group', 'research')->count(),
        'governance_documents' => Document::where('category_group', 'governance')->count(),
    ];

    return response()->json([
        'success' => true,
        'data' => $stats
    ]);
}
    /**
     * Get contracts for a specific affiliate by name
     */
    public function getAffiliateContracts(Request $request, $affiliateName): JsonResponse
    {
        try {
            $user = $request->attributes->get('supabase_user');
            $userModel = \App\Models\User::where('supabase_uid', $user['sub'])->first();

            if (!$userModel) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            $userAffiliateId = $this->getUserAffiliateId($userModel);
            $isOrganization = $this->isOrganizationUser($userModel);

            // Map affiliate names from the screenshot to database values
            $affiliateMapping = [
                'iowa-professional-associate-staff' => 'Iowa Professional Associate Staff',
                'iowa-staff-union' => 'Iowa Staff Union',
                'kansas-aso' => 'Kansas ASO',
                'kansas-pso' => 'Kansas PSO',
                'kentucky-easo' => 'Kentucky EASO',
                'minnesota-tempo' => 'Minnesota TEMPO',
                'minnesota-usm' => 'Minnesota USM',
                'missouri-aso' => 'Missouri ASO',
                'missouri-ssa' => 'Missouri SSA',
                'nebraska-sa' => 'Nebraska SA',
                'north-dakota-so' => 'North Dakota SO',
                'oklahoma-aso' => 'Oklahoma ASO',
                'south-dakota-so' => 'South Dakota SO',
                'texas-aso' => 'Texas ASO',
                'texas-psa' => 'Texas PSA',
                'weac-associate-staff' => 'WEAC Associate Staff',
                'weac-professional-staff' => 'WEAC Professional Staff'
            ];

            // Get the actual affiliate name from the mapping or use the parameter
            $actualAffiliateName = $affiliateMapping[$affiliateName] ?? $affiliateName;

            $query = Document::where('database_source', 'contracts')
                ->where('is_archived', false)
                ->whereHas('affiliate', function ($q) use ($actualAffiliateName) {
                    $q->where('name', 'ilike', '%' . $actualAffiliateName . '%');
                })
                ->with(['affiliate', 'uploader']);

            // Access control - non-national users can only see their affiliate's contracts
            if (!$isOrganization && $userAffiliateId) {
                $query->where('affiliate_id', $userAffiliateId);

                // Also verify the requested affiliate matches user's affiliate
                $userAffiliate = \App\Models\Affiliate::find($userAffiliateId);
                if (!$userAffiliate || !str_contains(strtolower($userAffiliate->name), strtolower($actualAffiliateName))) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Access denied to this affiliate\'s contracts'
                    ], 403);
                }
            }

            // Apply search if provided
            if ($request->filled('search')) {
                $useSimplified = $request->get('use_simplified_syntax', true);
                $this->applySearchSyntax($query, $request->search, $useSimplified);
            }

            // Apply additional filters
            if ($request->filled('state')) {
                $query->where('state', $request->state);
            }

            if ($request->filled('employer')) {
                $query->where('employer', 'ilike', '%' . $request->employer . '%');
            }

            if ($request->filled('cbc')) {
                $query->where('cbc', 'ilike', '%' . $request->cbc . '%');
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            // Expiration date filters
            if ($request->filled('expiring_soon')) {
                $thresholdDate = now()->addDays($request->get('days', 90));
                $query->where('expiration_date', '<=', $thresholdDate)
                    ->where('expiration_date', '>=', now());
            }

            if ($request->filled('expired')) {
                $query->where('expiration_date', '<', now());
            }

            // Ordering
            $orderBy = $request->get('order_by', 'expiration_date');
            $orderDir = $request->get('order_dir', 'asc');
            $query->orderBy($orderBy, $orderDir);

            // Pagination
            $perPage = $request->get('per_page', 25);
            if ($perPage === 'All') {
                $contracts = $query->get();
            } else {
                $perPage = min(max((int)$perPage, 1), 100);
                $contracts = $query->paginate($perPage);
            }

            // Format response data
            $data = collect($contracts->items() ?? $contracts)->map(function ($contract) {
                return [
                    'id' => $contract->id,
                    'title' => $contract->title,
                    'affiliate' => $contract->affiliate ? [
                        'id' => $contract->affiliate->id,
                        'name' => $contract->affiliate->name,
                        'code' => $contract->affiliate->code
                    ] : null,
                    'employer' => $contract->employer,
                    'state' => $contract->state,
                    'cbc' => $contract->cbc,
                    'expiration_date' => $contract->expiration_date,
                    'effective_date' => $contract->effective_date,
                    'status' => $contract->status,
                    'file_name' => $contract->file_name,
                    'uploaded_at' => optional($contract->uploadLog)->created_at,
                    'is_expired' => $contract->expiration_date && $contract->expiration_date < now(),
                    'days_until_expiration' => $contract->expiration_date ?
                        now()->diffInDays($contract->expiration_date, false) : null
                ];
            });

            // Get affiliate info for the response
            $affiliate = \App\Models\Affiliate::where('name', 'ilike', '%' . $actualAffiliateName . '%')->first();

            return response()->json([
                'success' => true,
                'data' => $data,
                'affiliate_info' => $affiliate ? [
                    'id' => $affiliate->id,
                    'name' => $affiliate->name,
                    'code' => $affiliate->code,
                    'region' => $affiliate->region
                ] : null,
                'meta' => [
                    'current_page' => $contracts->currentPage() ?? 1,
                    'last_page' => $contracts->lastPage() ?? 1,
                    'per_page' => $contracts->perPage() ?? $contracts->count(),
                    'total' => $contracts->total() ?? $contracts->count(),
                    'affiliate_name' => $actualAffiliateName,
                    'total_contracts' => $contracts->total() ?? $contracts->count()
                ],
                'message' => 'Affiliate contracts retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Affiliate contracts error: ' . $e->getMessage(), [
                'affiliate_name' => $affiliateName,
                'ip' => $request->ip(),
                'params' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve affiliate contracts',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function listFolders()
    {
        $folders = DocumentFolder::get();

        return response()->json([
            'success' => true,
            'data' => $folders,
            'message' => 'Folders retrieved successfully'
        ]);
    }
    public function storeFolder(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'parent_id' => 'nullable|exists:document_folders,id',
        ]);

        $folder = DocumentFolder::create($validated);

        return response()->json([
            'success' => true,
            'data' => $folder,
            'message' => 'Folder created successfully'
        ], 201);
    }

    public function stream(Request $request, $id)
    {
        $document = Document::findOrFail($id);

        // Generate short-lived signed URL (e.g. 60 seconds)
        $signedUrl = Storage::disk('supabase')->temporaryUrl(
            $document->file_path,
            now()->addSeconds(60)
        );

        // Stream the file contents
        $fileContents = file_get_contents($signedUrl);

        return response($fileContents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $document->file_name . '"',
        ]);
    }

    // public function status(Request $request)
    // {
    //     try {
    //         $affiliate_id = Member::where('user_id', Auth::id())->first()->affiliate_id;

    //         $thirtyDaysAgo = Carbon::now()->subDays(30);

    //         // Update contract statuses based on expiration dates
    //         DB::transaction(function () use ($affiliate_id) {
    //             // Mark expired contracts
    //             Document::where('affiliate_id', $affiliate_id)
    //                 ->where('type', 'contract')
    //                 ->where('status', 'active')
    //                 ->whereNotNull('expiration_date')
    //                 ->where('expiration_date', '<', now())
    //                 ->update(['status' => 'expired']);

    //             // Mark expiring soon contracts
    //             Document::where('affiliate_id', $affiliate_id)
    //                 ->where('type', 'contract')
    //                 ->where('status', 'active')
    //                 ->whereNotNull('expiration_date')
    //                 ->where('expiration_date', '>=', now())
    //                 ->where('expiration_date', '<=', now()->addDays(90))
    //                 ->update(['status' => 'active']); // Keep as active but we'll identify as expiring in stats
    //         });

    //         $stats = [
    //             'total_documents' => Document::where('affiliate_id', $affiliate_id)
    //                 ->where('is_archived', false)
    //                 ->count(),

    //             'active_contracts' => Document::where('affiliate_id', $affiliate_id)
    //                 ->where('type', 'contract')
    //                 ->where('status', 'active')
    //                 ->where('is_archived', false)
    //                 ->count(),

    //             'expiring_soon' => Document::where('affiliate_id', $affiliate_id)
    //                 ->where('type', 'contract')
    //                 ->where('status', 'active')
    //                 ->where('is_archived', false)
    //                 ->whereNotNull('expiration_date')
    //                 ->where('expiration_date', '>=', now())
    //                 ->where('expiration_date', '<=', now()->addDays(90))
    //                 ->count(),

    //             'expired_contracts' => Document::where('affiliate_id', $affiliate_id)
    //                 ->where('type', 'contract')
    //                 ->where('status', 'expired')
    //                 ->where('is_archived', false)
    //                 ->count(),

    //             'recent_uploads' => Document::where('affiliate_id', $affiliate_id)
    //                 ->where('is_archived', false)
    //                 ->where('created_at', '>=', $thirtyDaysAgo)
    //                 ->count(),
    //         ];

    //         return response()->json([
    //             'success' => true,
    //             'data' => $stats,
    //             'message' => 'Affiliate statistics retrieved successfully'
    //         ]);
    //     } catch (\Exception $e) {
    //         Log::error('Get affiliate stats error: ' . $e->getMessage());
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Failed to retrieve affiliate statistics'
    //         ], 500);
    //     }
    // }

public function status(Request $request)
{
    try {
        $user = Auth::user();
        $member = Member::where('user_id', $user->id)->first();
        $affiliate_id = $member->affiliate_id;

        $thirtyDaysAgo = Carbon::now()->subDays(30);

        // Determine document type based on request or user context
        $categoryGroup = $request->get('category_group', 'all'); // research, governance, or all

        // Base query with affiliate filtering
        $baseQuery = Document::where('is_archived', false);
        
        // Filter by affiliate if user is not national admin
        if (!$this->isOrganizationUser($user)) {
            $baseQuery->where(function($q) use ($affiliate_id) {
                $q->where('affiliate_id', $affiliate_id)
                  ->orWhereNull('affiliate_id');
            });
        }

        // Filter by category group if specified
        if ($categoryGroup !== 'all') {
            $baseQuery->where('category_group', $categoryGroup);
        }

        // Update contract statuses based on expiration dates
        DB::transaction(function () use ($affiliate_id, $categoryGroup) {
            $expiredQuery = Document::where('type', 'contract')
                ->where('status', 'active')
                ->whereNotNull('expiration_date')
                ->where('expiration_date', '<', now());

            $expiringQuery = Document::where('type', 'contract')
                ->where('status', 'active')
                ->whereNotNull('expiration_date')
                ->where('expiration_date', '>=', now())
                ->where('expiration_date', '<=', now()->addDays(90));

            // Apply affiliate filter if not national admin
            if ($affiliate_id && !$this->isOrganizationUser(Auth::user())) {
                $expiredQuery->where('affiliate_id', $affiliate_id);
                $expiringQuery->where('affiliate_id', $affiliate_id);
            }

            // Apply category group filter if specified
            if ($categoryGroup !== 'all') {
                $expiredQuery->where('category_group', $categoryGroup);
                $expiringQuery->where('category_group', $categoryGroup);
            }

            $expiredQuery->update(['status' => 'expired']);
            $expiringQuery->update(['status' => 'active']);
        });

        // Calculate stats
        $stats = [
            'total_documents' => (clone $baseQuery)->count(),

            'active_contracts' => (clone $baseQuery)
                ->where('type', 'contract')
                ->where('status', 'active')
                ->count(),

            'expiring_soon' => (clone $baseQuery)
                ->where('type', 'contract')
                ->where('status', 'active')
                ->whereNotNull('expiration_date')
                ->where('expiration_date', '>=', now())
                ->where('expiration_date', '<=', now()->addDays(90))
                ->count(),

            'expired_contracts' => (clone $baseQuery)
                ->where('type', 'contract')
                ->where('status', 'expired')
                ->count(),

            'recent_uploads' => (clone $baseQuery)
                ->where('created_at', '>=', $thirtyDaysAgo)
                ->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
            'meta' => [
                'affiliate_id' => $affiliate_id,
                'category_group' => $categoryGroup,
                'is_national' => $this->isOrganizationUser($user)
            ],
            'message' => 'Document statistics retrieved successfully'
        ]);
    } catch (\Exception $e) {
        Log::error('Get document stats error: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Failed to retrieve document statistics'
        ], 500);
    }
}


    // ============================= trying new data fetching ================================================

    public function fetch(Request $request)
    {
        $folderId = $request->input('folder_id');
        $localSearch = $request->input('local_search');
        $globalSearch = $request->input('global_search');
        $type = $request->input('type');

        $documentQuery = Document::query();
        $folderQuery = DocumentFolder::query();

        if (!$globalSearch) {
            if ($folderId) {
                $documentQuery->where('folder_id', $folderId);
                $folderQuery->where('parent_id', $folderId);
            } else {
                $documentQuery->whereNull('folder_id');
                $folderQuery->whereNull('parent_id');
            }
        }

        if ($localSearch) {
            $documentQuery->where('name', 'like', "%{$localSearch}%");
            $folderQuery->where('name', 'like', "%{$localSearch}%");
        }

        if ($globalSearch) {
            $documentQuery->where('name', 'like', "%{$globalSearch}%");
            $folderQuery->where('name', 'like', "%{$globalSearch}%");
        }

        $folders = [];
        $documents = [];

        if ($type === 'all' || $type === 'folder') {
            $folders = $folderQuery->get();
        }

        if ($type === 'all' || $type === 'document') {
            $documents = $documentQuery->paginate(10);
        }

        return response()->json([
            'folders' => $folders,
            'documents' => $documents,
        ]);
    }

    public function search(Request $request)
    {

        $query = Document::query();

        if ($request->has('search')) {
            $query->preciseSearch($request->query('search'));
        }

        $data = $query->get();

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'total' => $data->count(),
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => $data->count(),
            ]
        ]);
    }

    public function createFolder(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'folder_name' => 'required|string|max:255',
                'parent_id' => 'nullable|exists:document_folders,id',
                'affiliate_id' => 'nullable|exists:affiliates,id',
            ]);

            $folder = DocumentFolder::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'Folder created successfully.',
                'data' => $folder,
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    /**
 * Update document category group only
 */
public function updateCategoryGroup(Request $request, $id): JsonResponse
{
    try {
        $validated = $request->validate([
            'category_group' => 'required|in:research,governance'
        ]);

        $document = Document::findOrFail($id);
        $document->category_group = $validated['category_group'];
        $document->save();

        // Log activity
        ActivityLog::create([
            'user_id' => Auth::id(),
            'action' => 'document_category_group_updated',
            'auditable_type' => Document::class,
            'auditable_id' => $document->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'new_values' => ['category_group' => $validated['category_group']]
        ]);

        return response()->json([
            'success' => true,
            'data' => $document,
            'message' => 'Document category group updated successfully'
        ]);
    } catch (\Exception $e) {
        Log::error('Update category group error: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Failed to update category group'
        ], 500);
    }
}
}
