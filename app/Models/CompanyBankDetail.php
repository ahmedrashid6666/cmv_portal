<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The company's own bank accounts, printed on customer-facing documents
 * (Outstanding Statement) so a customer knows where to send payment. See the
 * migration docblock for why this is separate from `Bank`/BankService.
 */
class CompanyBankDetail extends Model
{
    protected $fillable = [
        'bank_name', 'account_name', 'account_number', 'iban', 'swift_code', 'branch', 'currency', 'is_default',
    ];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    /** Only one bank account can be "the default" shown on a fresh statement. */
    protected static function booted(): void
    {
        static::saved(function (self $bank) {
            if ($bank->is_default) {
                static::where('id', '!=', $bank->id)->update(['is_default' => false]);
            }
        });
    }

    /**
     * Which bank to print on a statement, from a request's `bank_id`:
     * present + non-empty → that bank; present but empty (explicitly chosen
     * "no bank details") → null; absent entirely → whichever is the default.
     */
    public static function resolveFor(Request $request): ?self
    {
        if ($request->filled('bank_id')) {
            return self::find($request->input('bank_id'));
        }
        if ($request->has('bank_id')) {
            return null;
        }

        return self::where('is_default', true)->first();
    }
}
