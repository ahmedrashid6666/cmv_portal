<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'contact', 'notes', 'opening_balance'];

    protected function casts(): array
    {
        return ['opening_balance' => 'decimal:2'];
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
}
