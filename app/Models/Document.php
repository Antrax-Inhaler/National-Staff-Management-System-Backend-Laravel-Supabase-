<?php

namespace App\Models;

use App\Enums\RoleEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\PdfToText\Pdf;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;
use Illuminate\Support\Str;


class Document extends BaseModel
{
    use HasFactory, SoftDeletes;
    protected $table = 'documents';
    protected $fillable = [
        'affiliate_id',
        'title',
        'category',
        'category_group',
        'description',
        'type',
        'folder_id',
        'file_name',
        'file_path',
        'file_size',
        'expiration_date',
        'state_id',
        'effective_date',
        'status',
        'keywords',
        'is_public',
        'content_extract',

        'award_date',
        'arbitrator',
        'outcome',
        'user_id',
        'uploaded_by'
    ];

    protected $casts = [
        'uploaded_at' => 'datetime',
        'is_active' => 'boolean',
        'file_size' => 'integer',
        'category' => 'array'
    ];
    protected $hidden = [
        'content_extract',
        'search_vector',
    ];

    // Relationships
    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }


    public function scopeContractFilter(Builder $query, Request $request)
    {
        $contract_status = $request->query('status');
        $expire_from = $request->query('expire_from');
        $expire_to = $request->query('expire_to');
        $effective_from = $request->query('effective_from');
        $effective_to = $request->query('effective_to');
        $contract_employer = $request->query('contract_employer');

        if ($expire_from) {
            $query->whereDate('expiration_date', '>=', $expire_from);
        }

        if ($expire_to) {
            $query->whereDate('expiration_date', '<=', $expire_to);
        }

        if ($effective_from) {
            $query->whereDate('effective_date', '>=', $effective_from);
        }

        if ($effective_to) {
            $query->whereDate('effective_date', '<=', $effective_to);
        }

        if ($contract_status) {
            $statuses = is_array($contract_status) ? $contract_status : explode(',', $contract_status);
            $query->whereIn('status', $statuses);
        }

        if ($contract_employer) {
            // Convert request input to array if it's a comma-separated string
            $values = is_array($contract_employer)
                ? $contract_employer
                : explode(',', $contract_employer);

            $user = Auth::user();
            $affiliate_id = null;
            $is_national = $user->hasRole(RoleEnum::nationalRoles());

            if (!$is_national) {
                $affiliate_id = $user->member?->affiliate_id;
                if (!$affiliate_id) {
                    return $query;
                }
            }

            // Determine cache key based on role
            $cache_key = $affiliate_id
                ? "contract_employer_options_" . $affiliate_id
                : "contract_employer_options";

            $employer_cache = Cache::get($cache_key, []);

            // Map slugs to DB values
            $selected_values = array_filter(array_map(
                fn($id) => $employer_cache[$id] ?? null,
                $values
            ));

            if (!empty($selected_values)) {
                // Apply affiliate restriction for non-national users
                $query->when(!$is_national, fn($q) => $q->where('affiliate_id', $affiliate_id))
                    ->whereIn('employer', $selected_values);
            }
        }



        return $query;
    }

    public function scopeArbitrationFilter(Builder $query, Request $request)
    {
        $outcome = $request->query('outcome');
        $arbitrator = trim($request->query('arbitrator', ''));
        $award_date_to = $request->query('award_date_to');
        $award_date_from = $request->query('award_date_from');

        if (!empty($arbitrator)) {
            $query->where('arbitrator', 'ilike', '%' . $arbitrator . '%');
        }

        if ($award_date_to) {
            $query->where('award_date', '<=', $award_date_to);
        }

        if ($award_date_from) {
            $query->where('award_date', '>=', $award_date_from);
        }

        if ($outcome) {
            $outcomes = is_array($outcome) ? $outcome : explode(',', $outcome);
            $query->whereIn('outcome', $outcomes);
        }

        return $query;
    }

    public function scopeBaseFilter(Builder $query, Request $request)
    {
        $document_type = $request->query('document_type');
        $public_document = $request->query('public');
        $category = $request->query('category');

        if ($public_document) {
            $query->where('is_public', filter_var($public_document, FILTER_VALIDATE_BOOLEAN));
        }

        if ($document_type) {
            $document_types = is_array($document_type) ? $document_type : explode(',', $document_type);
            $query->whereIn('type', $document_types);
        }

        if ($category) {
            $categories = is_array($category) ? $category : explode(',', $category);
            $query->where(function ($q) use ($categories) {
                foreach ($categories as $cat) {
                    $q->orWhereJsonContains('category', $cat);
                }
            });
        }

        return $query;
    }

    public function scopeAffiliateFilter(Builder $query, Request $request)
    {
        $affiliate_id   = $request->query('affiliate_id');
        $affiliate_type = $request->query('affiliate_type');
        $org_region     = $request->query('org_region');
        $employer = $request->query('employer');
        $cbc_region = $request->query('cbc_region');
        $state = $request->query('state');

        if ($affiliate_id) {
            $ids = is_array($affiliate_id)
                ? $affiliate_id
                : explode(',', $affiliate_id);

            // normalize values
            $ids = array_map('trim', $ids);

            $hasNone = in_array('none', $ids, true);

            // remove "none" from actual IDs
            $ids = array_filter($ids, fn($id) => $id !== 'none');

            $query->where(function ($q) use ($ids, $hasNone) {
                if (!empty($ids)) {
                    $q->whereIn('affiliate_id', $ids);
                }

                if ($hasNone) {
                    $q->orWhereNull('affiliate_id');
                }
            });
        }

        if ($affiliate_type || $org_region || $employer || $cbc_region || $state) {
            $query->whereHas('affiliate', function ($q) use ($request) {
                $q->baseFilter($request);
            });
        }

        return $query;
    }


    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForAffiliate($query, $affiliateId)
    {
        return $query->where('affiliate_id', $affiliateId);
    }

    public function scopeOrganizationResources($query)
    {
        return $query->whereNull('affiliate_id');
    }

    public function scopeType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function scopeCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    public function scopeFullTextSearch($query, $searchTerm)
    {
        if (empty($searchTerm)) {
            return $query;
        }

        return $query->whereRaw("search_vector @@ plainto_tsquery('english', ?)", [$searchTerm])
            ->orderByRaw("ts_rank(search_vector, plainto_tsquery('english', ?)) DESC", [$searchTerm]);
    }

    public function scopeWithFilters($query, array $filters)
    {
        if (! empty($filters['type'])) {
            $query->type($filters['type']);
        }

        if (! empty($filters['category'])) {
            $query->category($filters['category']);
        }

        if (! empty($filters['affiliate_id'])) {
            $query->forAffiliate($filters['affiliate_id']);
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        return $query;
    }

    public function scopeSimplifiedSearch(Builder $query, string $searchString): Builder
    {
        // Spaces = OR operation
        $terms = preg_split('/\s+/', $searchString);

        return $query->where(function ($q) use ($terms) {
            foreach ($terms as $term) {
                if (str_starts_with($term, '+')) {
                    // Required term
                    $cleanTerm = substr($term, 1);
                    $q->where('content_extract', 'ilike', '%' . $cleanTerm . '%')
                        ->orWhere('title', 'ilike', '%' . $cleanTerm . '%');
                } elseif (str_starts_with($term, '-')) {
                    // Excluded term
                    $cleanTerm = substr($term, 1);
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

    public function scopePreciseSearch(Builder $query, string $searchString): Builder
    {
        // Handle exact phrases in quotes
        preg_match_all('/"([^"]+)"/', $searchString, $phraseMatches);
        $phrases = $phraseMatches[1];

        // Remove phrases from search string to get individual terms
        $remainingString = preg_replace('/"([^"]+)"/', '', $searchString);
        $terms = array_filter(preg_split('/\s+/', $remainingString));

        return $query->where(function ($q) use ($phrases, $terms) {
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

    public function scopeAdvancedFilters(Builder $query, Request $request, ?int $affiliate_id = null): Builder
    {

        if ($request->filled('affiliate_id')) {
            if ($request->query('affiliate_id') == 'none') {
                $query->whereNull('affiliate_id');
            } else {
                $query->where('affiliate_id', $request->affiliate_id);
            }
        }

        if ($request->filled('affiliate')) {
            $query->whereHas('affiliate', function ($q) use ($request) {
                $q->where('name', 'ilike', '%' . $request->affiliate . '%');
            });
        }

        if ($request->filled('title')) {
            $query->where('title', 'ilike', '%' . $request->title . '%');
        }

        if ($request->filled('search_syntax')) {
            // Add logic for simplified vs precise search
        }

        if ($request->filled('contract_expiration_date_from')) {
            $query->where('expiration_date', '>=', $request->contract_expiration_date_from);
        }

        if ($request->filled('contract_expiration_date_to')) {
            $query->where('expiration_date', '<=', $request->contract_expiration_date_to);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('year')) {
            $query->where('year', $request->year);
        }

        if ($request->filled('is_archived')) {
            $query->where('is_archived', $request->boolean('is_archived'));
        }

        if ($affiliate_id) {
            $query->where('affiliate_id', $affiliate_id);
        }

        if ($request->filled('is_public')) {
            $query->where('is_public', $request->boolean('is_public'));
        }

        return $query;
    }

    public function scopeRepository(Builder $query, string $repository): Builder
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

            default:
                // Optionally, throw or ignore unknown repositories
                break;
        }

        return $query;
    }


    // Helper methods
    public function extractTextFromPdf($pdfPath): ?string
    {
        try {
            if (! file_exists($pdfPath)) {
                Log::warning("PDF not found at path: {$pdfPath}");
                return null;
            }

            $parser = new Parser();
            $pdf = $parser->parseFile($pdfPath);
            $text = $pdf->getText();

            if (empty($text)) {
                Log::warning("No text extracted from PDF: {$pdfPath}");
                return null;
            }

            // Clean up text
            $text = preg_replace('/\s+/', ' ', $text);
            $text = trim($text);

            return substr($text, 0, 50000);
        } catch (\Exception $e) {
            Log::error("PDF text extraction failed for {$pdfPath}: " . $e->getMessage());
            return null;
        }
    }




    public function getFileExtension(): string
    {
        return pathinfo($this->file_name, PATHINFO_EXTENSION);
    }

    public function isPdf(): bool
    {
        return strtolower($this->getFileExtension()) === 'pdf';
    }

    public function getReadableFileSize(): string
    {
        $size = $this->file_size;
        if ($size === 0) {
            return '0 Bytes';
        }

        $sizeNames = ['Bytes', 'KB', 'MB', 'GB'];
        $i = floor(log($size, 1024));

        return round($size / pow(1024, $i), 2) . ' ' . $sizeNames[$i];
    }
    public function uploadLog()
    {
        return $this->hasOne(ActivityLog::class, 'auditable_id')
            ->where('auditable_type', self::class)
            ->where('action', 'document_uploaded')
            ->latest('created_at');
    }
    public function folder()
    {
        return $this->belongsTo(DocumentFolder::class, 'folder_id');
    }
}
