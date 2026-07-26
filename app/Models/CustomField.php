<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomField extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'label', 'type', 'options', 'required', 'active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'required' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('active', true)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Validation rules for this field when submitted on a transaction.
     *
     * @return array<int, mixed>
     */
    public function rules(): array
    {
        $rules = [$this->required ? 'required' : 'nullable'];
        $rules[] = match ($this->type) {
            'number' => 'numeric',
            'date' => 'date',
            'select' => 'in:'.implode(',', $this->options ?? []),
            default => 'string',
        };

        return $rules;
    }
}
