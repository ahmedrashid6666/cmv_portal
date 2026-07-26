<?php

namespace App\Models\Concerns;

use App\Models\ActivityLog;

/**
 * Records create/update/delete/restore activity for a model into activity_logs.
 * A model may define auditLabel(): string and auditExclude(): array.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn ($model) => $model->logActivity('created'));
        static::updated(fn ($model) => $model->logActivity('updated'));
        static::deleted(function ($model) {
            $soft = method_exists($model, 'isForceDeleting') && $model->isForceDeleting();
            $model->logActivity($soft ? 'force_deleted' : 'deleted');
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(fn ($model) => $model->logActivity('restored'));
        }
    }

    public function activityLogs()
    {
        return $this->morphMany(ActivityLog::class, 'auditable')->latest();
    }

    public function logActivity(string $action): void
    {
        $changes = null;
        if ($action === 'updated') {
            $exclude = array_merge(['updated_at', 'created_at'], $this->auditExclude());
            $changes = [];
            foreach ($this->getChanges() as $key => $new) {
                if (in_array($key, $exclude, true)) {
                    continue;
                }
                $changes[$key] = [$this->getOriginal($key), $new];
            }
            if (empty($changes)) {
                return; // nothing meaningful changed
            }
        }

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'auditable_type' => static::class,
            'auditable_id' => $this->getKey(),
            'label' => $this->auditLabel(),
            'changes' => $changes,
        ]);
    }

    public function auditLabel(): string
    {
        return $this->name ?? $this->invoice_no ?? $this->email ?? ('#'.$this->getKey());
    }

    /**
     * @return array<int, string>
     */
    public function auditExclude(): array
    {
        return [];
    }
}
