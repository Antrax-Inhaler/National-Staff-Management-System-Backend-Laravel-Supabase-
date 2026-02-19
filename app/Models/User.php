<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Services\SupabaseUserService;
use App\Traits\SupabaseAuth;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class User extends BaseAuthenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes, SupabaseAuth;

    protected $table = 'users';
    protected $fillable = [
        'name',
        'email',
        'password',
        'supabase_uid',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'supabase_uid',
        'created_at',
        'updated_at',
        'email_verified_at'
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    protected static function booted()
    {
        static::deleting(function ($user) {
            $member = Member::withTrashed()->firstWhere('user_id', $user->id);
            if ($member) {
                if (!$user->isForceDeleting()) {
                    $member->delete();
                } else {
                    $member->forceDelete();
                }
            }
        });

        static::restored(function ($user) {
            $member = Member::withTrashed()
                ->where('user_id', $user->id)
                ->first();

            if ($member?->trashed()) {
                $member->restore();
            }
        });
    }

    /**
     * Get the member record associated with the user.
     */
    public function member()
    {
        return $this->hasOne(Member::class, 'user_id');
    }

    /**
     * Get the national roles for the user through member.
     */
    public function nationalRoles()
    {
        return $this->hasManyThrough(
            MemberOrganizationRole::class,
            Member::class,
            'user_id', // Foreign key on members table...
            'member_id', // Foreign key on member_national_roles table...
            'id', // Local key on users table...
            'id' // Local key on members table...
        );
    }

    /**
     * Get the affiliate officer roles for the user through member.
     */
    public function officerRoles()
    {
        return $this->hasManyThrough(
            AffiliateOfficer::class,
            Member::class,
            'user_id', // Foreign key on members table...
            'member_id', // Foreign key on affiliate_officers table...
            'id', // Local key on users table...
            'id' // Local key on members table...
        );
    }

    public function hasRole($roles): bool
    {
        $roles = (array) $roles;

        return $this->roles()
            ->whereIn('name', $roles)
            ->exists();
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, $this->qualifyTable('user_roles'), 'user_id', 'role_id')->using(UserRole::class);
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, $this->qualifyTable('user_permissions'), 'user_id', 'permission_id')->using(UserPermission::class);
    }
}
