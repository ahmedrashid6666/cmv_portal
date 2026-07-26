<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Bank extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'account_no', 'opening_balance'];

    protected function casts(): array
    {
        return ['opening_balance' => 'decimal:2'];
    }
}
