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

class ResearchDocumentController extends Controller
{
    public function overview(Request $request)
    {
        $search = $request->query('search', null);
        $simplified = $request->query('simplified', true);

        $user = Auth::user();
        $query = Document::with(['affiliate', 'uploader', 'folder'])
            ->where('category_group', 'research');

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

        // $query->advancedFilters($request);

        if ($request->filled('folder_id')) {
            $folderId = $request->get('folder_id');
            $query->where('folder_id', $folderId);
        }

        $sortOrder = $request->get('order_dir', 'asc');
        $perPage = $request->query('per_page', 20);

        if (!$request->query('type')) {
            $sortOrder = in_array(strtolower($sortOrder), ['asc', 'desc']) ? $sortOrder : 'asc';
            $query->orderByRaw("
            LEAST(
                COALESCE(award_date, '9999-12-31'),
                COALESCE(expiration_date, '9999-12-31')
                COALESCE(effective_date, '9999-12-31')
            ) {$sortOrder}
        ");
            return ApiCollection::makeFromQuery($query, $perPage, null, null);
        }

        if ($request->query('type')) {
            $query->where('type', $request->query('type'));
        }

        $sortBy = $request->get('order_by', 'status');
        $sortOrder = $request->get('order_dir', 'asc');

        return ApiCollection::makeFromQuery($query, $perPage, $sortBy, $sortOrder);
    }

    // public function affiliate(Request $request, $affiliate_uid)
    // {
    //     $affiliate_id = Affiliate::where('public_uid', $affiliate_uid)->value('id');
    //     $search = $request->query('search', null);
    //     $simplified = $request->query('simplified', true);

    //     $query = Document::with(['affiliate', 'uploader', 'folder'])
    //         ->where('category_group', 'research');

    //     $query->where(function ($q) use ($affiliate_id) {
    //         $q->where('affiliate_id', $affiliate_id);
    //     });


    //     if ($search && $simplified) {
    //         $query->simplifiedSearch($search);
    //     }

    //     if ($search && !$simplified) {
    //         $query->preciseSearch($search);
    //     }

    //     $query->advancedFilters($request);

    //     if ($request->filled('folder_id')) {
    //         $folderId = $request->get('folder_id');
    //         $query->where('folder_id', $folderId);
    //     }

    //     if ($request->query('type')) {
    //         $query->where('type', $request->query('type'));
    //     }

    //     $sortBy = $request->get('order_by', 'created_at');
    //     $sortOrder = $request->get('order_dir', 'desc');

    //     $perPage = $request->query('per_page', 20);

    //     return ApiCollection::makeFromQuery($query, $perPage, $sortBy, $sortOrder);
    // }

    public function folders(Request $request)
    {
        $folderId = $request->query('folder'); // null = root
        $public_uid = $request->query('public_uid');
        $user = Auth::user();
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

        if ($folderId) {
            $currentFolder = DocumentFolder::with([
                'children' => function ($q) {
                    $q->where('category_group', 'research');
                },
                'affiliate',
                'documents' => function ($q) use ($affiliateId) {
                    $q->where('affiliate_id', $affiliateId)
                        ->where('category_group', 'research');
                },
                'documents.affiliate',
                'documents.uploader'
            ])->where('public_uid', $folderId)
                ->firstOrFail();
        } else {
            // Fetch or create root folder
            $currentFolder = DocumentFolder::with([
                'children' => function ($q) {
                    $q->where('category_group', 'research');
                },
                'affiliate',
                'documents' => function ($q) use ($affiliateId) {
                    $q->where('affiliate_id', $affiliateId)
                        ->where('category_group', 'research');
                },
                'documents.affiliate',
                'documents.uploader'
            ])->where('affiliate_id', $affiliateId)
                ->where('root', true)
                ->firstOrCreate(
                    ['affiliate_id' => $affiliateId, 'root' => true],
                    [
                        'folder_name' => null,
                    ]
                );
        }


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
            'type' => 'required|in:contract,arbitration,mou,research',
            'category_group' => 'required|in:research',
            'keywords' => 'nullable|string',
            'file' => 'required|file|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt|max:10240',

            // CONTRACT
            'status' => 'required_if:type,contract|prohibited_unless:type,contract|in:active,expired,negotiation,draft',
            'expiration_date' => 'required_if:type,contract|prohibited_unless:type,contract|nullable|date',
            'effective_date' => 'required_if:type,contract|prohibited_unless:type,contract|nullable|date',

            // ARBITRATION
            'award_date' => 'required_if:type,arbitration|prohibited_unless:type,arbitration|date',
            'arbitrator' => 'required_if:type,arbitration|prohibited_unless:type,arbitration|string|max:255',
            'outcome' => 'required_if:type,arbitration|prohibited_unless:type,arbitration|string|in:denied,sustained,partial,dismissed,settlement,supplemental_award,voluntary_settlement',

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
                    'category_group' => $validated['category_group'] ?? 'research',
                    'type' => $validated['type'] ?? null,
                    'keywords' => $validated['keywords'] ?? null,

                    'file_name' => $fileName,
                    'file_path' => $filePath,
                    'file_size' => $file->getSize(),
                    'content_extract' => $text_extracted,

                    // CONTRACT
                    'status' => $validated['status'] ?? null,
                    'expiration_date' => $validated['expiration_date'] ?? null,
                    'effective_date' => $validated['effective_date'] ?? null,

                    // ARBITRATION
                    'award_date' => $validated['award_date'] ?? null,
                    'arbitrator' => $validated['arbitrator'] ?? null,
                    'outcome' => $validated['outcome'] ?? null,


                    'is_public' => $validated['is_public'] ?? false,
                ]);
            });

            return response()->json([
                'success' => true,
                'message' => 'Research document uploaded successfully'
            ], 201);
        } catch (\Throwable $th) {

            if (isset($filePath)) {
                Storage::disk('supabase')->delete($filePath);
            }

            Log::error('RESEARCH UPLOAD ERROR', [
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
            'type' => 'nullable|in:contract,arbitration,mou,research',
            'keywords' => 'nullable|string',

            // CONTRACT
            'status' => 'nullable|required_if:type,contract|in:active,expired,negotiation,draft',
            'expiration_date' => 'nullable|required_if:type,contract|date',
            'effective_date' => 'nullable|required_if:type,contract|date',

            // ARBITRATION
            'award_date' => 'nullable|required_if:type,arbitration|date',
            'arbitrator' => 'nullable|required_if:type,arbitration|string|max:255',
            'outcome' => 'nullable|required_if:type,arbitration|in:denied,sustained,partial,dismissed,settlement,supplemental_award,voluntary_settlement',

            // OPTIONAL
            'is_archived' => 'nullable|boolean',
            'is_public' => 'nullable|boolean',
        ]);

        try {
            DB::transaction(function () use ($validated, $id) {
                $document = Document::findOrFail($id);
                $document->update($validated);
            });

            return response()->json([
                'success' => true,
                'message' => 'Research document updated successfully'
            ], 200);
        } catch (\Throwable $th) {
            Log::error('RESEARCH UPLOAD ERROR', [
                'exception' => $th,
            ]);
            return response()->json([
                'message' => 'Update failed',
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $document = Document::findOrFail($id);
            $affiliate_id = $document->affiliate_id;

            if (isset($document->file_path)) {
                Storage::disk('supabase')->delete($document->file_path);
            }
            $document->delete();

            Log::info('Document deleted', [
                'document_id' => $id,
                'deleted_by' => Auth::id()
            ]);
            Cache::forget('contract_employer_options');
            if ($affiliate_id) {
                Cache::forget('contract_employer_options_' . $affiliate_id);
            }
            return response()->json([
                'success' => true,
                'message' => 'Document deleted successfully'
            ], 200);
        } catch (\Throwable $th) {

            Log::error('RESEARCH UPLOAD ERROR', [
                'exception' => $th,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete document',
                'error' => $th->getMessage()
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
            $query->where('category_group', 'research');
        }]);

        return $documentFolder;
    }

    public function affiliate(Request $request, $uid)
    {
        $search = $request->query('search', null);
        $simplified = $request->query('simplified', true);

        $query = Document::with(['affiliate', 'uploader', 'folder'])
            ->where('category_group', 'research')
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
            ->where('category_group', 'research');

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
        $query->contractFilter($request);
        $query->arbitrationFilter($request);
        $query->baseFilter($request);

        $sort_by = $request->get('sort_by', null);
        $sort_order = $request->get('sort_order', null);
        $perPage = $request->query('per_page', 20);

        $queryParams = $request->except(['per_page', 'page']);

        if (empty($queryParams)) {
            $sort_order = in_array(strtolower($sort_order), ['asc', 'desc']) ? $sort_order : 'desc';
            $query->orderByRaw("
                CASE 
                    WHEN award_date IS NULL AND expiration_date IS NULL AND effective_date IS NULL 
                    THEN 1 
                    ELSE 0 
                END ASC,
                LEAST(
                    COALESCE(award_date, '9999-12-31'::date),
                    COALESCE(expiration_date, '9999-12-31'::date),
                    COALESCE(effective_date, '9999-12-31'::date)
                ) {$sort_order}
            ");
            return ApiCollection::makeFromQuery($query, $perPage, $sort_by, $sort_order);
        }

        if ($request->query('document_type') === 'contract') {

            $contract_params = $request->except(['per_page', 'page', 'document_type']);

            if (empty($contract_params)) {

                $query->orderByRaw("
                    CASE 
                        WHEN status = 'active' THEN 0
                        WHEN status = 'expired' THEN 1
                        ELSE 2
                    END ASC
                ")
                            ->orderByRaw("
                    COALESCE(expiration_date, effective_date) DESC
                ");

                return ApiCollection::makeFromQuery($query, $perPage, null, null);
            }

            return ApiCollection::makeFromQuery($query, $perPage, $sort_by, $sort_order);
        }



        if ($request->query('type')) {
            $query->where('type', $request->query('type'));
        }

        return ApiCollection::makeFromQuery($query, $perPage, $sort_by, $sort_order);
    }


    public function stream(Request $request, $id)
    {
        $document = Document::findOrFail($id);

        // Generate short-lived signed URL (e.g. 60 seconds)
        $signedUrl = Storage::disk('supabase')->temporaryUrl(
            $document->file_path,
            now()->addMinutes(5)
        );

        return response()->stream(function () use ($signedUrl) {
            readfile($signedUrl);
        }, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $document->file_name . '"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function arbitrators()
    {
        $user = Auth::user();
        $affiliate_id = null;

        if (!$user->hasRole(RoleEnum::nationalRoles())) {
            $member = Member::where('user_id', $user->id)->first();
            if (!$member?->affiliate_id) {
                return response()->json([]);
            }
            $affiliate_id = $member->affiliate_id;
        }

        return Document::query()
            ->where('category_group', 'research')
            ->when($affiliate_id, fn($q) => $q->where('affiliate_id', $affiliate_id))
            ->whereNotNull('arbitrator')
            ->whereNotIn('arbitrator', ['N/A', ''])
            ->distinct()
            ->orderBy('arbitrator')
            ->pluck('arbitrator')
            ->values()
            ->toArray();

        return response()->json($arbitrators);
    }
}
