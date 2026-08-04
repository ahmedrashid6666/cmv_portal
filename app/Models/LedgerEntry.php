<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A single Daily-Credit or Borrowed-Amount record. The balance, status and
 * return date are derived automatically from total and paid amounts on save.
 *
 *   balance = max(0, total - paid)
 *   status  = pending (paid 0) | partial (0 < paid < total) | returned (balance 0)
 *   return_date auto-set the moment it becomes fully settled (if left blank)
 */
class LedgerEntry extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const TYPE_CREDIT = 'daily_credit';

    public const TYPE_BORROWED = 'borrowed';

    protected $fillable = [
        'type', 'entry_date', 'party_name', 'contact_numbers', 'reference', 'vehicle_number', 'remarks',
        'total_amount', 'currency', 'paid_amount', 'balance_amount', 'status', 'return_date', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'contact_numbers' => 'array',
            'entry_date' => 'date:Y-m-d',
            'return_date' => 'date:Y-m-d',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'balance_amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $entry) {
            $total = (float) $entry->total_amount;
            $paid = (float) $entry->paid_amount;
            $balance = round(max(0, $total - $paid), 2);
            $entry->balance_amount = $balance;

            if ($paid <= 0) {
                $entry->status = 'pending';
            } elseif ($balance <= 0) {
                $entry->status = 'returned';
                $entry->return_date = $entry->return_date ?: Carbon::today()->toDateString();
            } else {
                $entry->status = 'partial';
            }

            if ($balance > 0) {
                $entry->return_date = null; // not settled yet
            }
        });
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payments()
    {
        return $this->hasMany(LedgerPayment::class)->latest('payment_date');
    }

    /** Itemized date + description + amount rows that build up total_amount. */
    public function details()
    {
        return $this->hasMany(LedgerEntryDetail::class)->orderBy('detail_date');
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function auditLabel(): string
    {
        return $this->party_name.' — '.number_format((float) $this->total_amount, 2);
    }

    public function auditExclude(): array
    {
        return ['balance_amount', 'status'];
    }
}
