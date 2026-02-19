<?php

namespace App\Models;

use App\Services\MemberIdGeneratorService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\UpdatesUserRoles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use App\Models\User;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class Member extends BaseModel
{
    use HasFactory, UpdatesUserRoles, LogsActivity, SoftDeletes;

    protected $table = 'members';
    protected $fillable = [
        'public_uid',
        'user_id',
        'affiliate_id',
        'member_id',
        'first_name',
        'last_name',
        'level',
        'employment_status',
        'status',
        'address_line1',
        'address_line2',
        'city',
        'state_id',
        'state',
        'zip_code',
        'work_email',
        'work_phone',
        'work_fax',
        'home_email',
        'home_phone',
        'mobile_phone',
        'date_of_birth',
        'date_of_hire',
        'gender',
        'profile_photo_url',
        'self_id',
        'non_nso',
        'created_by',
        'updated_by',
        'photo_path',
        'photo_signed_expires_at',
        'photo_signed_url'
    ];

    protected $casts = [
        'non_nso' => 'boolean',
        'date_of_birth' => 'date:Y-m-d',
        'date_of_hire' => 'date:Y-m-d',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    protected $hidden =
    [
        'created_at',
        'updated_at',
        'deleted_at',
        'created_by',
        'updated_by',
        'photo_path',
        'photo_signed_expires_at',
        'profile_photo_url',
    ];

    public function getNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    protected function generateMemberId(): string
    {
        $prefix = 'MEM';
        $timestamp = now()->format('ymdHis');
        $maxAttempts = 5;
        $attempts = 0;

        do {
            if ($attempts === 0) {
                $random = Str::upper(Str::random(4));
            } else {
                $random = Str::upper(Str::random(6 + $attempts));
            }

            $generatedId = "{$prefix}{$timestamp}{$random}";

            $exists = self::where('member_id', $generatedId)->exists();
            $attempts++;
        } while ($exists && $attempts < $maxAttempts);

        if ($exists) {
            $generatedId = 'MEM_' . Str::uuid();
        }

        return $generatedId;
    }

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->public_uid)) {
                $model->public_uid = (string) Str::uuid();
            }
        });

        // Add a static property to track if we're in CSV import mode
        static::saving(function ($model) {
            // Check if this is a CSV import operation
            if (app()->runningInConsole() || request()->has('csv_import')) {
                // Don't sync emails during CSV import
                return;
            }

            // Original email sync logic
            if (isset($model->home_email) && !empty($model->home_email)) {
                $model->work_email = $model->home_email;
            } elseif (isset($model->work_email) && !empty($model->work_email)) {
                $model->home_email = $model->work_email;
            }
        });

        static::updated(function ($model) {
            // Skip during CSV import
            if (app()->runningInConsole() || request()->has('csv_import')) {
                return;
            }

            if ($model->isDirty('affiliate_id')) {
                $model->updateUserRoles($model->user_id);
            }

            // Remove the saveQuietly() from updated event - move to created only
            // if (empty($model->member_id) || is_null($model->member_id)) {
            //     $service = new MemberIdGeneratorService();
            //     $model->member_id = $service->generate(...);
            //     $model->saveQuietly(); // Remove this
            // }

            if ($model->isDirty('work_email') && !empty($model->work_email)) {
                $model->syncEmailToUser($model->work_email);
            }
        });

        static::created(function ($model) {
            // Always generate member_id, even during CSV import
            if (empty($model->member_id) || is_null($model->member_id)) {
                $service = new MemberIdGeneratorService();
                $model->member_id = $service->generate(
                    affiliate: $model->affiliate_id,
                    memberId: $model->id
                );

                // Use update instead of saveQuietly to avoid events
                DB::table('members')
                    ->where('id', $model->id)
                    ->update(['member_id' => $model->member_id]);
            }

            if (empty($model->public_uid)) {
                DB::table('members')
                    ->where('id', $model->id)
                    ->update([
                        'public_uid' => (string) Str::uuid()
                    ]);
            }

            // Skip email sync during CSV import
            if (!(app()->runningInConsole() || request()->has('csv_import')) && !empty($model->work_email)) {
                $model->syncEmailToUser($model->work_email);
            }
        });
    }

    /**
     * Sync member work_email to user email
     */
    // In the Member model's syncEmailToUser method:
    protected function syncEmailToUser(string $email): void
    {
        if (!$this->user_id) {
            return;
        }

        try {
            $user = User::find($this->user_id);

            if ($user) {
                // Only update if email is different
                if ($user->email !== $email) {
                    $user->email = $email;
                    $user->save(); // This will trigger User model's updated event

                    Log::info("Synced email for user {$user->id}: {$user->email}");

                    // After save, the User model will automatically:
                    // 1. Check if supabase_uid exists
                    // 2. If not, create Supabase account
                    // 3. If yes, update Supabase email
                } else {
                    Log::debug("User email already matches, no sync needed", [
                        'user_id' => $user->id,
                        'email' => $email
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error("Failed to sync email for member {$this->id}: " . $e->getMessage());
        }
    }

    public function getCurrentPositionsOrNullAttribute()
    {
        $positions = $this->currentPositions;

        return $positions->isEmpty() ? null : $positions;
    }






    // ============================================================== Scopes =========================================================================

    public function scopeSearch(Builder $query, $keyword)
    {

        $keyword = str_replace('+', ' ', urldecode($keyword));
        $words = preg_split('/\s+/', trim($keyword));

        return $query->where(function ($q) use ($words) {
            foreach ($words as $word) {
                $q->whereAny(
                    [
                        'members.first_name',
                        'members.last_name',
                        'members.work_email',
                        'members.home_email',
                        'members.member_id',
                    ],
                    'ilike',
                    "%{$word}%"
                );
            }
        });
    }

    public function scopeBaseFilter(Builder $query, Request $request)
    {
        $phone = $request->query('phone');
        $email = $request->query('email');
        $state = $request->query('state');
        $employment_status = $request->query('employment_status');
        $self_id = $request->query('self_id');
        $level = $request->query('level');
        $gender = $request->query('gender');
        $date_of_hire_to = $request->query('date_of_hire_to');
        $date_of_hire_from = $request->query('date_of_hire_from');
        $date_of_birth_to = $request->query('date_of_birth_to');
        $date_of_birth_from = $request->query('date_of_birth_from');

        if ($phone) {
            if ($phone === 'with') {
                $query->whereNotNull('mobile_phone');
            } elseif ($phone === 'without') {
                $query->whereNull('mobile_phone');
            }
        }

        if ($email) {
            if ($email === 'with') {
                $query->whereNotNull('work_email');
            } elseif ($email === 'without') {
                $query->whereNull('work_email');
            }
        }

        // SELF_ID filter
        if ($self_id) {
            $self_ids = is_array($self_id) ? $self_id : explode(',', $self_id);

            $hasNotSet = in_array('Not Set', $self_ids, true);
            $filteredIds = array_diff($self_ids, ['Not Set']);

            $query->where(function ($q) use ($filteredIds, $hasNotSet) {
                if (!empty($filteredIds)) {
                    $q->whereIn('self_id', $filteredIds);
                }
                if ($hasNotSet) {
                    $q->orWhereNull('self_id');
                }
            });
        }

        // EMPLOYMENT_STATUS filter
        if ($employment_status) {
            $statuses = is_array($employment_status) ? $employment_status : explode(',', $employment_status);

            $hasNotSet = in_array('Not Set', $statuses, true);
            $filteredStatuses = array_diff($statuses, ['Not Set']);

            $query->where(function ($q) use ($filteredStatuses, $hasNotSet) {
                if (!empty($filteredStatuses)) {
                    $q->whereIn('employment_status', $filteredStatuses);
                }
                if ($hasNotSet) {
                    $q->orWhereNull('employment_status');
                }
            });
        }

        // LEVEL filter
        if ($level) {
            $levels = is_array($level) ? $level : explode(',', $level);

            $hasNotSet = in_array('Not Set', $levels, true);
            $filteredLevels = array_diff($levels, ['Not Set']);

            $query->where(function ($q) use ($filteredLevels, $hasNotSet) {
                if (!empty($filteredLevels)) {
                    $q->whereIn('level', $filteredLevels);
                }
                if ($hasNotSet) {
                    $q->orWhereNull('level');
                }
            });
        }

        if ($gender) {
            $genders = is_array($gender) ? $gender : explode(',', $gender);
            $query->whereIn('gender', $genders);
        }

        if ($state) {
            $query->where('state', $state);
        }

        if ($date_of_hire_to) {
            $query->where('date_of_hire', '<=', $date_of_hire_to);
        }

        if ($date_of_hire_from) {
            $query->where('date_of_hire', '>=', $date_of_hire_from);
        }

        if ($date_of_birth_to) {
            $query->where('date_of_birth', '<=', $date_of_birth_to);
        }

        if ($date_of_birth_from) {
            $query->where('date_of_birth', '>=', $date_of_birth_from);
        }

        return $query;
    }

    public function scopeAffiliateFilter(Builder $query, Request $request)
    {
        $affiliate_id   = $request->query('affiliate_id');
        $cbc            = $request->query('cbc_region');
        $affiliate_type = $request->query('affiliate_type');
        $org_region     = $request->query('org_region');
        $affiliate_employer = $request->query('affiliate_employer');

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

        if ($cbc || $affiliate_type || $org_region || $affiliate_employer) {
            $query->whereHas('affiliate', function ($q) use ($cbc, $affiliate_type, $org_region, $affiliate_employer) {

                $q->when($cbc, function ($q) use ($cbc) {
                    $values = is_array($cbc) ? $cbc : explode(',', $cbc);
                    $q->whereIn('cbc_region', $values);
                });

                $q->when($affiliate_type, function ($q) use ($affiliate_type) {
                    $values = is_array($affiliate_type) ? $affiliate_type : explode(',', $affiliate_type);
                    $q->whereIn('affiliate_type', $values);
                });

                $q->when($org_region, function ($q) use ($org_region) {
                    $values = is_array($org_region) ? $org_region : explode(',', $org_region);
                    $q->whereIn('org_region', $values);
                });

                $q->when($affiliate_employer, function ($q) use ($affiliate_employer) {
                    // Get cached mapping: slug => DB value
                    $employer_cache = Cache::get('employer_options', []);

                    $values = is_array($affiliate_employer)
                        ? $affiliate_employer
                        : explode(',', $affiliate_employer);

                    $selected_values = array_filter(array_map(
                        fn($id) => $employer_cache[$id] ?? null,
                        $values
                    ));

                    if (!empty($selected_values)) {
                        $q->whereIn('employer_name', $selected_values);
                    }
                });
            });
        }

        return $query;
    }




    // ============================================================== Relationships =========================================================================

    /**
     * Relationship to User model
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }


    /**
     * Get the affiliate that the member belongs to.
     */
    public function affiliate()
    {
        return $this->belongsTo(Affiliate::class, 'affiliate_id');
    }

    /**
     * Get the national roles for the member.
     */
    public function nationalRoles()
    {
        return $this->hasMany(MemberOrganizationRole::class, 'member_id');
    }

    /**
     * Get the officer positions for the member.
     */
    public function officerPositions()
    {
        return $this->hasMany(AffiliateOfficer::class, 'member_id')
            ->where('is_vacant', 0)
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>', now());
            });
    }

    public function currentPosition()
    {
        return $this->hasOne(AffiliateOfficer::class, 'member_id')
            ->where('is_vacant', false)
            ->where(function ($q) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>', now());
            })
            ->with('position'); // eager load the OfficerPosition name
    }

    /**
     * Get all officer positions (including historical) for the member.
     */
    public function allOfficerPositions()
    {
        return $this->hasMany(AffiliateOfficer::class, 'member_id');
    }

    public function officerRoles()
    {
        return $this->hasMany(AffiliateOfficer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the last updater of the member record.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
    public function currentPositions()
    {
        return $this->hasMany(AffiliateOfficer::class, 'member_id')
            ->where('is_vacant', false)
            ->where(function ($q) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>', now());
            })
            ->with('position');
    }

    public function hasPosition($positions): bool
    {
        $positions = (array) $positions;

        return $this->currentPositions()
            ->whereHas('position', function ($q) use ($positions) {
                $q->whereIn('name', $positions);
            })
            ->exists();
    }
}
