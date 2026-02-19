<?php

namespace App\Traits;

use App\Models\User;
use App\Services\SupabaseUserService;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;

trait SupabaseAuth
{
    protected static function bootSupabaseAuth()
    {
        static::updated(function (User $model) {
            if (app()->runningInConsole()) return;

            if (isset($model->getChanges()['email']) && $model->supabase_uid) {
                $oldEmail = $model->getOriginal('email');
                $newEmail = $model->email;

                if ($oldEmail === $newEmail) return; // safeguard

                $service = app(SupabaseUserService::class);

                try {
                    $service->updateUserEmail($model->supabase_uid, $newEmail);
                } catch (\Throwable $e) {
                    Log::error("Failed to update Supabase email for user {$model->id}: {$e->getMessage()}");
                }
            }
        });

        static::forceDeleted(function (User $user) {
            if (app()->runningInConsole()) return;
            
            $user->getConnection()->afterCommit(function () use ($user) {
                if ($user->supabase_uid) {
                    try {
                        app(SupabaseUserService::class)
                            ->deleteUser(supabaseUserId: $user->supabase_uid);

                        Log::info('Deleted Supabase user', [
                            'user_id' => $user->id,
                            'supabase_uid' => $user->supabase_uid
                        ]);
                    } catch (\Throwable $e) {
                        Log::error('Failed to delete Supabase user', [
                            'user_id' => $user->id,
                            'supabase_uid' => $user->supabase_uid,
                            'error' => $e->getMessage()
                        ]);
                    }
                } else {
                    Log::info('No Supabase UID to delete', [
                        'user_id' => $user->id
                    ]);
                }
            });
        });
    }
}
