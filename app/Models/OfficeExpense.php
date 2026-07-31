<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A standalone office / overhead expense — money paid out that is not tied to
 * a shipment transaction (rent, salaries, utilities, stationery, etc.). It is
 * a real cash/bank outflow: BalanceService reduces the balance of the bucket
 * (cash or bank) that matches the chosen payment method.
 */
class OfficeExpense extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'expense_date', 'expense_category_id', 'description', 'amount',
        'currency', 'payment_method_id', 'contact_numbers', 'remarks', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date:Y-m-d',
            'amount' => 'decimal:2',
            'contact_numbers' => 'array',
        ];
    }

    public function category()
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
