<?php

namespace App\Http\Controllers\v1;

use App\Enums\RoleEnum;
use App\Http\Controllers\Controller;
use App\Http\Resources\ApiCollection;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser;

class OrganizationDocumentController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->query('search', null);
        $simplified = $request->query('simplified', true);

        $query = Document::with(['affiliate', 'uploader', 'folder'])
            ->where('category_group', 'national');

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

        if ($request->query('type')) {
            $query->where('type', $request->query('type'));
        }

        return ApiCollection::makeFromQuery($query, $perPage, $sort_by, $sort_order);
    }


    public function upload(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:contract,arbitration,mou,bylaws,research,general',
            'category_group' => 'required|in:national',
            'keywords' => 'nullable|string',
            'category' => 'required|json',
            'file' => 'required|file|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt|max:10240',

            // CONTRACT
            'status' => 'required_if:type,contract|prohibited_unless:type,contract|in:active,expired,negotiation,draft',
            'expiration_date' => 'required_if:type,contract|prohibited_unless:type,contract|nullable|date',
            'effective_date' => 'required_if:type,contract|prohibited_unless:type,contract|nullable|date',

            // ARBITRATION
            'award_date' => 'required_if:type,arbitration|prohibited_unless:type,arbitration|date',
            'arbitrator' => 'required_if:type,arbitration|prohibited_unless:type,arbitration|string|max:255',
            'outcome' => 'required_if:type,arbitration|prohibited_unless:type,arbitration|string|in:denied,sustained,partial,dismissed,settlement,supplemental_award,voluntary_settlement',

            'is_archived' => 'nullable|boolean',
            'is_public' => 'nullable|boolean',
        ]);



        try {

            $file = $request->file('file');
            $fileName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
                . '_' . time() . '.' . $file->getClientOriginalExtension();
            $filePath = $file->storeAs('documents/national', $fileName, 'supabase');

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
                $document = Document::create([
                    'user_id' => $user->id,
                    'uploaded_by' => $user->name ?? null,
                    'title' => $validated['title'] ?? null,
                    'category_group' => 'national',
                    'description' => $validated['description'] ?? null,
                    'file_name' => $fileName,
                    'file_path' => $filePath,
                    'file_size' => $file->getSize(),
                    'keywords' => $validated['keywords'] ?? null,
                    'category' => json_decode($validated['category'], true),
                    'content_extract' => $text_extracted,
                    'type' => $validated['type'] ?? null,

                    // CONTRACT
                    'status' => $validated['status'] ?? null,
                    'expiration_date' => $validated['contract_expiration_date'] ?? null,
                    'effective_date' => $validated['effective_date'] ?? null,

                    // ARBITRATION
                    'award_date' => $validated['award_date'] ?? null,
                    'arbitrator' => $validated['arbitrator'] ?? null,
                    'outcome' => $validated['outcome'] ?? null,
                ]);
            });

            return response()->json([
                'success' => true,
                'message' => 'Organization document uploaded successfully'
            ], 201);
        } catch (\Throwable $th) {

            if (isset($filePath)) {
                Storage::disk('supabase')->delete($filePath);
            }

            Log::error('NATIONAL DOCUMENT UPLOAD ERROR', [
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
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'type' => 'nullable|in:contract,arbitration,mou,research',
            'keywords' => 'nullable|string',
            'category' => 'nullable',

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
                'message' => 'Organization document updated successfully'
            ], 200);
        } catch (\Throwable $th) {

            Log::error('NATIONAL UPLOAD ERROR', [
                'exception' => $th,
            ]);
            return response()->json([
                'message' => 'Update failed',
            ], 500);
        }
    }

    public function destroy($id)
    {
        $user = Auth::user();

        if (!$user->hasRole(RoleEnum::nationalRoles())) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete document, unauthorized',
            ], 401);
        }

        try {
            $document = Document::findOrFail($id);

            if (isset($document->file_path)) {
                Storage::disk('supabase')->delete($document->file_path);
            }

            $document->delete();

            Log::info('Document deleted', [
                'document_id' => $id,
                'deleted_by' => Auth::id()
            ]);

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

    public function arbitrators()
    {
        return Document::query()
            ->where('category_group', 'national')
            ->whereNotNull('arbitrator')
            ->whereNotIn('arbitrator', ['N/A', ''])
            ->distinct()
            ->orderBy('arbitrator')
            ->pluck('arbitrator')
            ->values()
            ->toArray();
    }
}
