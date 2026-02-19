<?php

namespace App\Http\Controllers\v1;

use App\Enums\OfficersEnum;
use App\Enums\RoleEnum;
use App\Http\Controllers\Controller;
use App\Http\Resources\ApiCollection;
use App\Models\Affiliate;
use App\Models\Document;
use App\Models\DocumentFolder;
use App\Models\Member;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Smalot\PdfParser\Parser;

class GovernanceDocumentController extends Controller
{
    public function overview(Request $request)
    {
        $search = $request->query('search', null);
        $simplified = $request->query('simplified', true);

        $user = Auth::user();
        $query = Document::with(['affiliate', 'uploader', 'folder'])
            ->where('category_group', 'governance');

        if (!$user->hasRole(RoleEnum::nationalRoles())) {
            $member = Member::where('user_id', $user->id)->first();
            if (!$member?->affiliate_id) {
                abort(403, 'No assigned affiliate');
            }

            // Include Public Documents
            $query->where(function ($q) use ($member) {
                $q->where('affiliate_id', $member->affiliate_id)
                    ->orWhere('is_public', true);
            });
        }

        if ($search && $simplified) {
            $query->simplifiedSearch($search);
        }

        if ($search && !$simplified) {
            $query->preciseSearch($search);
        }

        $query->advancedFilters($request);

        if ($request->filled('folder_id')) {
            $folderId = $request->get('folder_id');
            $query->where('folder_id', $folderId);
        }

        if ($request->query('type')) {
            $query->where('type', $request->query('type'));
        }

        $sortBy = $request->get('order_by', 'created_at');
        $sortOrder = $request->get('order_dir', 'desc');

        $perPage = $request->query('per_page', 20);

        return ApiCollection::makeFromQuery($query, $perPage, $sortBy, $sortOrder);
    }

    public function folders(Request $request)
    {
        $folderId = $request->query('folder'); // null = root
        $public_uid = $request->query('public_uid');
        $user = Auth::user();

        // Determine affiliate ID
        $affiliateId = Affiliate::where('public_uid', $public_uid)->value('id');

        if (!$user->hasRole(RoleEnum::nationalRoles()) && !$affiliateId) {
            $member = Member::where('user_id', $user->id)->first();
            if (!$member?->affiliate_id) {
                abort(403, 'No assigned affiliate');
            }
            $affiliateId = $member->affiliate_id;
        }

        if (!$affiliateId) {
            abort(404, 'Affiliate not found');
        }

        // Fetch folder
        if ($folderId) {
            $currentFolder = DocumentFolder::where('public_uid', $folderId)->firstOrFail();
        } else {
            // Root folder: fetch or create
            $currentFolder = DocumentFolder::firstOrCreate(
                ['affiliate_id' => $affiliateId, 'root' => true],
                ['folder_name' => null]
            );
        }

        // Load relationships with proper filters
        $currentFolder->load([
            'children' => function ($q) {
                $q->where('category_group', 'governance');
            },
            'affiliate',
            'documents' => function ($q) use ($affiliateId) {
                $q->where('category_group', 'governance')
                    ->where('affiliate_id', $affiliateId)
                ;
            },
            'documents.affiliate',
            'documents.uploader'
        ]);

        // Prepare response
        $response = [
            'current_folder' => [
                'id' => $currentFolder->id,
                'display_name' => $currentFolder->display_name,
                'parent_id' => $currentFolder->parent_id,
            ],
            'path' => $currentFolder->getPath(),
            'folders' => $currentFolder->children->map(fn($f) => [
                'id' => $f->public_uid,
                'display_name' => $f->display_name,
            ]),
            'documents' => $currentFolder->documents,
        ];

        return response()->json($response);
    }


    public function upload(Request $request)
    {
        $validated = $request->validate([
            'affiliate_id' => [
                'nullable',
                'exists:affiliates,id',
            ],

            'affiliate_uid' => [
                'nullable',
                'uuid',
                'exists:affiliates,public_uid',
                Rule::requiredIf(
                    Auth::user()->hasRole(RoleEnum::nationalRoles())
                        && ! $request->input('affiliate_id')
                ),
            ],

            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:general,bylaws',
            'category_group' => 'required|in:governance',
            'keywords' => 'nullable|string',
            'file' => 'required|file|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt|max:10240',

            // OPTIONAL
            'is_archived' => 'nullable|boolean',
            'is_public' => 'nullable|boolean',
        ]);

        try {

            $file = $request->file('file');
            $fileName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
                . '_' . time() . '.' . $file->getClientOriginalExtension();
            $filePath = $file->storeAs('documents/affiliate', $fileName, 'supabase');

            $text_extracted = null;
            if (strtolower($file->getClientOriginalExtension()) === 'pdf') {
                try {
                    $parser = new Parser();
                    $pdf = $parser->parseFile($file->getRealPath());
                    $text = $pdf->getText();

                    if (!empty($text)) {
                        $text = preg_replace('/\s+/', ' ', $text);
                        $text = trim($text);
                        $text_extracted = mb_substr($text, 0, 50000);
                    } else {
                        Log::warning("No text extracted from PDF: {$filePath}");
                    }
                } catch (\Throwable $e) {
                    Log::error("PDF extraction failed: {$e->getMessage()}");
                }
            }

            DB::transaction(function () use ($validated, $request, $file, $text_extracted, $fileName, $filePath) {

                $user = Auth::user();
                $affiliate_id = null;

                if ($user->hasRole(RoleEnum::nationalRoles())) {
                    
                    // Organization users may specify affiliate
                    if (!empty($validated['affiliate_id'])) {
                        $affiliate_id = $validated['affiliate_id'];
                    } elseif (!empty($validated['affiliate_uid'])) {
                        $affiliate_id = Affiliate::where('public_uid', $validated['affiliate_uid'])
                            ->value('id');
                    }

                } else {

                    // Non-national users are locked to their own affiliate
                    $affiliate_id = $user->member?->affiliate_id;
                }

                if (! $affiliate_id) {
                    abort(403, 'No assigned affiliate');
                }

                Document::create([
                    'user_id' => $user->id,
                    'affiliate_id' => $affiliate_id,
                    'uploaded_by' => $user->name ?? null,

                    'title' => $validated['title'] ?? null,
                    'description' => $validated['description'] ?? null,
                    'category_group' => $validated['category_group'] ?? 'governance',
                    'type' => $validated['type'] ?? null,
                    'keywords' => $validated['keywords'] ?? null,

                    'file_name' => $fileName,
                    'file_path' => $filePath,
                    'file_size' => $file->getSize(),
                    'content_extract' => $text_extracted,

                    'is_public' => $validated['is_public'] ?? false,
                ]);
            });

            return response()->json([
                'success' => true,
                'message' => 'Governance document uploaded successfully'
            ], 201);
        } catch (\Throwable $th) {

            if (isset($filePath)) {
                Storage::disk('supabase')->delete($filePath);
            }

            Log::error('GOVERNANCE UPLOAD ERROR', [
                'exception' => $th,
            ]);
            return response()->json([
                'message' => 'Upload failed',
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'affiliate_id' => 'nullable|exists:affiliates,id',

            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'type' => 'nullable|in:general,bylaws',
            'keywords' => 'nullable|string',

            // OPTIONAL
            'is_archived' => 'nullable|boolean',
            'is_public' => 'nullable|boolean',
        ]);

        DB::transaction(function () use ($validated, $id) {
            $document = Document::findOrFail($id);
            $document->update($validated);
        });

        return response()->json([
            'success' => true,
            'message' => 'document updated successfully'
        ], 200);
    }

    public function destroy($id)
    {
        try {
            $document = Document::findOrFail($id);

            $document->delete();

            Log::info('Document deleted', [
                'document_id' => $id,
                'deleted_by' => Auth::id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Document deleted successfully'
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Document delete error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'document_id' => $id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete document',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function createFolder(Request $request)
    {
        $validated = $request->validate([
            'folder_name' => 'required|string|max:255',
            'affiliate_uid' => 'nullable|uuid|exists:affiliates,public_uid',
            'folder_uid' => 'nullable|uuid|exists:document_folders,public_uid',
            'category_group' => 'required'
        ]);

        DB::transaction(function () use ($validated) {
            $user = Auth::user();
            $affiliate_id = $validated['affiliate_id'] ?? null;
            $folder_uid = $validated['folder_uid'] ?? null;
            $affiliate_uid = $validated['affiliate_uid'] ?? null;

            if ($affiliate_uid) {
                $affiliate_id = Affiliate::where('public_uid', $affiliate_uid)->value('id');
            }

            if (!$user->hasRole(RoleEnum::nationalRoles()) && !$affiliate_uid) {
                $member = Member::where('user_id', $user->id)->first();
                if (!$member?->affiliate_id) {
                    abort(403, 'No assigned affiliate');
                }
                $affiliate_id = $member->affiliate_id;
            }

            if ($folder_uid) {
                $document_folder = DocumentFolder::where('public_uid', $folder_uid)->firstOrFail();
            } else {
                $document_folder = DocumentFolder::firstOrCreate(
                    [
                        'affiliate_id' => $affiliate_id,
                        'root' => true,
                        'parent_id' => null,
                    ],
                    [
                        'folder_name' => null,
                    ]
                );
            }

            $target_folder = $document_folder;
            DocumentFolder::create([
                'parent_id' => $target_folder->id,
                'folder_name' => $validated['folder_name'],
                'category_group' => $validated['category_group']
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Document deleted successfully'
        ], 200);
    }

    public function updateFolder(Request $request)
    {
        $validated = $request->validate([
            'folder_name' => 'required|string|max:255',
            'folder_uid' => 'nullable|uuid|exists:document_folders,public_uid',
        ]);

        DB::transaction(function () use ($validated) {
            $document = DocumentFolder::firstWhere('public_uid', $validated['folder_uid']);
            $document->update([
                'folder_name' => $validated['folder_name']
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Folder Name Updated'
        ], 200);
    }

    public function affiliateFolders()
    {
        $user = Auth::user();

        $member = Member::where('user_id', $user->id)->first();

        if (! $member?->affiliate_id) {
            abort(403, 'No assigned affiliate');
        }

        $documentFolder = DocumentFolder::firstOrCreate(
            [
                'affiliate_id' => $member->affiliate_id,
                'root' => true,
                'parent_id' => null,
            ],
            [
                'folder_name' => null, // optional, but explicit is good
            ]
        );

        // Load children recursively
        $documentFolder->load(['childrenRecursive' => function ($query) {
            $query->where('category_group', 'governance');
        }]);

        return $documentFolder;
    }


    public function affiliate(Request $request, $uid)
    {
        $search = $request->query('search', null);
        $simplified = $request->query('simplified', true);

        $query = Document::with(['affiliate', 'uploader', 'folder'])
            ->where('category_group', 'governance')
            ->whereHas('affiliate', function ($q) use ($uid) {
                $q->where('public_uid', $uid);
            });

        $query->baseFilter($request);

        if ($search && $simplified) {
            $query->simplifiedSearch($search);
        }

        if ($search && !$simplified) {
            $query->preciseSearch($search);
        }

        $sort_by = $request->query('sort_by', 'created_at');
        $sort_order = $request->query('sort_order', 'desc');
        $perPage = $request->query('per_page', 20);

        return ApiCollection::makeFromQuery($query, $perPage, $sort_by, $sort_order);
    }

    public function index(Request $request)
    {
        $search = $request->query('search', null);
        $simplified = $request->query('simplified', true);

        $user = Auth::user();
        $member = $user->member;

        $hasOrganizationRole = $user->hasRole(RoleEnum::nationalRoles());

        $hasOfficerPosition = $member?->hasPosition([
            OfficersEnum::PRESIDENT->value,
            OfficersEnum::BARGAINING_CHAIR->value,
            OfficersEnum::GRIEVANCE_CHAIR->value,
        ]) ?? false;

        $query = Document::with(['affiliate', 'uploader', 'folder'])
            ->where('category_group', 'governance');

        if (! $hasOrganizationRole && ! $hasOfficerPosition) {

            if (! $member?->affiliate_id) {
                abort(403, 'No assigned affiliate');
            }

            $query->where('affiliate_id', $member->affiliate_id);
        }

        if ($search && $simplified) {
            $query->simplifiedSearch($search);
        }

        if ($search && !$simplified) {
            $query->preciseSearch($search);
        }

        $query->affiliateFilter($request);
        $query->baseFilter($request);

        $sort_by = $request->query('sort_by', 'created_at');
        $sort_order = $request->query('sort_order', 'desc');
        $perPage = $request->query('per_page', 20);

        return ApiCollection::makeFromQuery($query, $perPage, $sort_by, $sort_order);
    }
}
