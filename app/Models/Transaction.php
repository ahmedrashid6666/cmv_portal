<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaction extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'transaction_date', 'invoice_no', 'boe_no',
        'customer_id', 'reference_id', 'vehicle_id',
        'customs_fees', 'gov_fees', 'profit', 'vat_rate', 'vat_amount', 'total_amount',
        'payment_method_id', 'credit_amount', 'remarks', 'attachment_path',
        'grand_total', 'net_profit', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'customs_fees' => 'decimal:2',
            'gov_fees' => 'decimal:2',
            'profit' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'credit_amount' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'net_profit' => 'decimal:2',
        ];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function reference()
    {
        return $this->belongsTo(Reference::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function expenses()
    {
        return $this->hasMany(TransactionExpense::class);
    }

    public function commissions()
    {
        return $this->hasMany(TransactionCommission::class);
    }

    public function creditPayments()
    {
        return $this->hasMany(CreditPayment::class);
    }
}
