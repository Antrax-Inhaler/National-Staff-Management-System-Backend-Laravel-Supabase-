<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Affiliate extends BaseModel
{
    use HasFactory, LogsActivity;
    protected $table = 'affiliates';
    protected $fillable = [
        'public_uid',
        'name',
        'employer_name',
        'state',
        'ein',
        'affiliation_date',
        'logo_url',
        'affiliate_type',
        'cbc_region',
        'org_region',
        'created_by',
        'updated_by',
        'logo_path',
        'logo_signed_expires_at',
        'logo_signed_url'
    ];

    protected $casts = [
        'affiliation_date' => 'date',
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
        'created_by',
        'updated_by',
        'logo_url',
        'logo_path',
        'logo_signed_expires_at'
    ];

    protected static function booted()
    {
        static::creating(function ($member) {
            $member->public_uid ??= (string) Str::uuid();
        });
    }

    // ========================================================================SCOPES====================================================================

    /**
     * Scope a query to filter by affiliate type.
     */
    public function scopeByType($query, $type)
    {
        return $query->where('affiliate_type', $type);
    }

    /**
     * Scope a query to filter by CBC region.
     */
    public function scopeByCbcRegion($query, $region)
    {
        return $query->where('cbc_region', $region);
    }

    /**
     * Scope a query to filter by ORG region.
     */
    public function scopeByNsoRegion($query, $region)
    {
        return $query->where('org_region', $region);
    }

    public function scopeSearch(Builder $query, $keyword)
    {

        $keyword = str_replace('+', ' ', urldecode($keyword));
        $words = preg_split('/\s+/', trim($keyword));

        return $query->where(function ($q) use ($words) {
            foreach ($words as $word) {
                $q->whereAny(
                    [
                        'affiliates.name',
                        'affiliates.ein',
                    ],
                    'ilike',
                    "%{$word}%"
                );
            }
        });
    }
    public function scopeBaseFilter(Builder $query, Request $request)
    {

        $affiliate_type = $request->query('affiliate_type');
        $affiliation_date_to = $request->query('affiliation_date_to');
        $affiliation_date_from = $request->query('affiliation_date_from');
        $cbc_region = $request->query('cbc_region');
        $org_region = $request->query('org_region');
        $state = $request->query('state');
        $employer = $request->query('employer');

        if ($affiliation_date_to) {
            $query->where('affiliation_date', '<=', $affiliation_date_to);
        }

        if ($affiliation_date_from) {
            $query->where('affiliation_date', '>=', $affiliation_date_from);
        }

        if ($state) {
            $states = is_array($state)
                ? $state
                : explode(',', $state);
            $states = array_map('trim', $states);
            $hasNone = in_array('not_set', $states, true);
            $states = array_filter($states, fn($t) => $t !== 'not_set');

            $query->where(function ($q) use ($states, $hasNone) {
                if (!empty($states)) {
                    $q->whereIn('state', $states);
                }
                if ($hasNone) {
                    $q->orWhereNull('state');
                }
            });
        }

        if ($affiliate_type) {
            $affiliate_types = is_array($affiliate_type)
                ? $affiliate_type
                : explode(',', $affiliate_type);

            $affiliate_types = array_map('trim', $affiliate_types);

            $hasNone = in_array('not_set', $affiliate_types, true);
            $affiliate_types = array_filter($affiliate_types, fn($t) => $t !== 'not_set');

            $query->where(function ($q) use ($affiliate_types, $hasNone) {
                if (!empty($affiliate_types)) {
                    $q->whereIn('affiliate_type', $affiliate_types);
                }

                if ($hasNone) {
                    $q->orWhereNull('affiliate_type');
                }
            });
        }

        if ($cbc_region) {
            $cbc_regions = is_array($cbc_region)
                ? $cbc_region
                : explode(',', $cbc_region);

            $cbc_regions = array_map('trim', $cbc_regions);

            $hasNone = in_array('not_set', $cbc_regions, true);
            $cbc_regions = array_filter($cbc_regions, fn($r) => $r !== 'not_set');

            $query->where(function ($q) use ($cbc_regions, $hasNone) {
                if (!empty($cbc_regions)) {
                    $q->whereIn('cbc_region', $cbc_regions);
                }

                if ($hasNone) {
                    $q->orWhereNull('cbc_region');
                }
            });
        }

        if ($org_region) {
            $org_regions = is_array($org_region)
                ? $org_region
                : explode(',', $org_region);

            $org_regions = array_map('trim', $org_regions);

            $hasNone = in_array('not_set', $org_regions, true);
            $org_regions = array_filter($org_regions, fn($r) => $r !== 'not_set');

            $query->where(function ($q) use ($org_regions, $hasNone) {
                if (!empty($org_regions)) {
                    $q->whereIn('org_region', $org_regions);
                }

                if ($hasNone) {
                    $q->orWhereNull('org_region');
                }
            });
        }

        if ($employer) {
            $employers = is_array($employer)
                ? $employer
                : explode(',', $employer); // Changed from $org_region to $employer
            $employers = array_map('trim', $employers);
            $hasNone = in_array('not_set', $employers, true);
            $employers = array_filter($employers, fn($r) => $r !== 'not_set');

            $query->where(function ($q) use ($employers, $hasNone) {
                if (!empty($employers)) {
                    $q->whereIn('employer_name', $employers);
                }
                if ($hasNone) {
                    $q->orWhereNull('employer_name');
                }
            });
        }
    }


    // ========================================================================RELATIONSHIPS====================================================================

    public function root_folder()
    {
        return $this->hasOne(DocumentFolder::class);
    }

    public function folders()
    {
        return $this->hasMany(DocumentFolder::class);
    }

    public function documents()
    {
        return $this->hasMany(Document::class);
    }

    /**
     * Get the members belonging to this affiliate.
     */
    public function members()
    {
        return $this->hasMany(Member::class, 'affiliate_id');
    }

    /**
     * Get the officers of this affiliate.
     */
    public function officers()
    {
        return $this->hasMany(AffiliateOfficer::class, 'affiliate_id');
    }

    /**
     * Get the current officers of this affiliate.
     */
    public function currentOfficers()
    {
        return $this->hasMany(AffiliateOfficer::class, 'affiliate_id')
            ->where('is_vacant', 0)
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>', now());
            });
    }

    /**
     * Get the user who created this affiliate.
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this affiliate.
     */
    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function links()
    {
        return $this->hasMany(Link::class);
    }

    public function domains()
    {
        return $this->hasMany(Domain::class);
    }



    /**
     * Get the formatted affiliation date.
     */
    public function getFormattedAffiliationDateAttribute()
    {
        return $this->affiliation_date?->format('m/d/Y');
    }

    /**
     * Get the logo URL with full path.
     */
    public function getLogoUrlAttribute($value)
    {
        if (!$value) {
            return null;
        }

        try {
            if (Storage::disk('supabase')->exists($value)) {
                return Storage::disk('supabase')->url($value);
            }
        } catch (\Exception $e) {
            // Log error but don't break the application
            Log::warning('Failed to get logo URL: ' . $e->getMessage());
        }

        return $value;
    }

    /**
     * Get the temporary signed URL for the logo (for frontend display).
     */
    public function getLogoUrlWithExpiryAttribute()
    {
        if (!$this->logo_url) {
            return null;
        }

        try {
            // Assuming the logo_url stored is the path in storage
            return \Storage::disk('supabase')->temporaryUrl(
                $this->logo_url,
                now()->addMinutes(60)
            );
        } catch (\Exception $e) {
            \Log::warning('Failed to generate signed logo URL: ' . $e->getMessage());
            return $this->logo_url; // Fallback to regular URL
        }
    }

    /**
     * Get the affiliate type options.
     */
    public static function getAffiliateTypeOptions()
    {
        return [
            'Associate' => 'Associate',
            'Professional' => 'Professional',
            'Wall-to-Wall' => 'Wall-to-Wall',
        ];
    }

    /**
     * Get the CBC region options.
     */
    public static function getCbcRegionOptions()
    {
        return [
            'Northeast' => 'Northeast',
            'Cooridor' => 'Cooridor',
            'South' => 'South',
            'Central' => 'Central',
            'Western' => 'Western',
        ];
    }

    /**
     * Get the ORG region options.
     */
    public static function getNsoRegionOptions()
    {
        return [
            '1' => 'Region 1',
            '2' => 'Region 2',
            '3' => 'Region 3',
            '4' => 'Region 4',
            '5' => 'Region 5',
            '6' => 'Region 6',
            '7' => 'Region 7',
        ];
    }
}
