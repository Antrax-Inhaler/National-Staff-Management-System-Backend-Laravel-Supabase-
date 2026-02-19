<?php

namespace App\Traits;

use App\Models\ActivityLog;
use App\Models\Affiliate;
use App\Models\Member;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

trait LogsActivity
{
    protected static function filterLogAttributes(array $attributes): array
    {
        $fieldsToExclude = [
            'updated_at',
            'deleted_at',
            'updated_by',
            'created_by',
            'logo_signed_url',
            'logo_signed_expires_at',
            'logo_path',
            'logo_url',
            'photo_path',
            'photo_signed_expires_at',
            'photo_signed_url',
            'state_id',
        ];

        foreach ($fieldsToExclude as $field) {
            unset($attributes[$field]);
        }

        return $attributes;
    }

    protected static function bootLogsActivity()
    {
        static::created(function (Model $model) {
            $attributes = static::filterLogAttributes($model->getAttributes());

            if (!empty($attributes)) {
                static::logActivity('created', $model, null, $attributes);
            }
        });

        static::updated(function (Model $model) {
            $changes = $model->getChanges();
            $original = array_intersect_key($model->getOriginal(), $changes);

            $changes = static::filterLogAttributes($changes);
            $original = static::filterLogAttributes($original);

            if (! empty($changes)) {
                static::logActivity('updated', $model, $original, $changes);
            }
        });

        static::deleted(function (Model $model) {
            if (static::modelUsesSoftDeletes($model) && $model->isForceDeleting()) {
                static::logActivity('deleted', $model, $model->getAttributes(), null);
            } elseif (static::modelUsesSoftDeletes($model)) {
                static::logActivity('archived', $model, $model->getAttributes(), null);
            } else {
                static::logActivity('deleted', $model, $model->getAttributes(), null);
            }
        });



        if (in_array(SoftDeletes::class, class_uses_recursive(static::class))) {
            static::restored(function (Model $model) {
                static::logActivity('restored', $model, $model->getAttributes(), null);
            });
        }
    }

    protected static function modelUsesSoftDeletes(Model $model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model));
    }

    protected static function logActivity(
        string $action,
        Model $model,
        ?array $oldValues,
        ?array $newValues
    ): void {
        // Prevent logging during seeding / console
        if (app()->runningInConsole()) {
            return;
        }

        $affiliateId = null;

        if ($model instanceof Member) {
            $affiliateId = $model->affiliate_id ?? null;
        }

        ActivityLog::create([
            'user_id'        => Auth::id(),
            'affiliate_id'   => $affiliateId,
            'action'         => $action,
            'auditable_type' => get_class($model),
            'auditable_id'   => $model->getKey(),
            'auditable_name' => $model->name ?? null,
            'old_values'     => $oldValues,
            'new_values'     => $newValues,
            'ip_address'     => Request::ip(),
            'user_agent'     => Request::userAgent(),
        ]);
    }
}
