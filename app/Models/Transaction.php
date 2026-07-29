<?php

namespace App\Models;

use App\Models\Concerns\Auditable;

use App\Observers\TransactionObserver;
use App\Services\TransactionCalculator;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy(TransactionObserver::class)]
class Transaction extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'transaction_date', 'invoice_no', 'boe_no',
        'customer_id', 'reference_id', 'vehicle_id',
        'customs_fees', 'gov_fees', 'gov_bank_id', 'profit', 'vat_rate', 'currency', 'vat_amount', 'total_amount',
        'payment_method_id', 'credit_amount', 'remarks', 'attachment_path',
        'grand_total', 'net_profit', 'created_by', 'custom_data',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date:Y-m-d',
            'customs_fees' => 'decimal:2',
            'gov_fees' => 'decimal:2',
            'profit' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'credit_amount' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'net_profit' => 'decimal:2',
            'custom_data' => 'array',
        ];
    }

    public function auditLabel(): string
    {
        return 'Invoice '.($this->invoice_no ?? '#'.$this->getKey());
    }

    /**
     * Derived fields are recomputed automatically — keep them out of history.
     *
     * @return array<int, string>
     */
    public function auditExclude(): array
    {
        return ['vat_amount', 'total_amount', 'grand_total', 'net_profit'];
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

    public function govBank()
    {
        return $this->belongsTo(Bank::class, 'gov_bank_id');
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

    /**
     * Recompute grand_total and net_profit from the persisted children
     * (expenses + commissions) and save. Call after syncing children.
     */
    public function recomputeTotals(): self
    {
        $calc = app(TransactionCalculator::class);

        $commissions = $this->commissions()
            ->get(['type', 'amount'])
            ->map(fn ($c) => ['type' => $c->type, 'amount' => $c->amount])
            ->all();

        $expenses = $this->expenses()
            ->get(['amount'])
            ->map(fn ($e) => ['amount' => $e->amount])
            ->all();

        $this->grand_total = $calc->grandTotal($this->total_amount, $commissions);
        $this->net_profit = $calc->netProfit(
            $this->profit,
            $calc->totalExpenses($expenses),
            $calc->commissionPayable($commissions),
        );
        $this->saveQuietly();

        return $this;
    }

    /**
     * Invoice payment status: paid | partial | unpaid.
     * A non-credit sale is paid at point of sale.
     */
    public function invoiceStatus(): string
    {
        if ((float) $this->credit_amount <= 0) {
            return 'paid';
        }
        $outstanding = (float) $this->creditOutstanding();
        if ($outstanding <= 0) {
            return 'paid';
        }

        // "partial" if any of the sale was already received — either up front
        // (grand_total − credit_amount) or through a later credit repayment.
        $receivedAtSale = (float) $this->grand_total - (float) $this->credit_amount;
        if ($receivedAtSale > 0 || $outstanding < (float) $this->credit_amount) {
            return 'partial';
        }

        return 'unpaid';
    }

    /** Outstanding credit for this transaction (credit_amount - payments). */
    public function creditOutstanding(): string
    {
        $calc = app(TransactionCalculator::class);
        $payments = $this->creditPayments()
            ->get(['amount'])
            ->map(fn ($p) => ['amount' => $p->amount])
            ->all();

        return $calc->creditOutstanding($this->credit_amount, $payments);
    }
}
