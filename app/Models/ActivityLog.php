<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    public const UPDATED_AT = null; // created_at only

    protected $fillable = ['user_id', 'action', 'auditable_type', 'auditable_id', 'label', 'changes'];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function auditable()
    {
        return $this->morphTo();
    }

    /** Short model name, e.g. "Transaction". */
    public function getModelNameAttribute(): string
    {
        return class_basename($this->auditable_type);
    }
}
