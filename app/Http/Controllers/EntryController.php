<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use App\Models\Customer;
use App\Models\CustomField;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Reference;
use App\Models\Setting;
use App\Services\BankService;
use Inertia\Inertia;

class EntryController extends Controller
{
    public function create(BankService $bankService)
    {
        return Inertia::render('Entry/Create', [
            // Transaction form options
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'references' => Reference::orderBy('name')->get(['id', 'name', 'company']),
            'paymentMethods' => PaymentMethod::orderBy('name')->get(['id', 'name']),
            'expenseCategories' => ExpenseCategory::orderBy('name')->get(['id', 'name']),
            'banks' => Bank::orderBy('name')->get(['id', 'name']),
            'customsBank' => $bankService->customsBank()?->only(['id', 'name']),
            'vatRate' => (float) Setting::get('vat_rate', 0),
            'customFields' => CustomField::active()->get(['key', 'label', 'type', 'options', 'required']),
            // Ledger metas
            'ledgerMetas' => [
                ['slug' => 'daily-credit', 'type' => 'daily_credit', 'label' => 'Daily Credit', 'partyLabel' => 'Customer Name', 'paidLabel' => 'Paid Amount', 'totalLabel' => 'Credit Amount'],
                ['slug' => 'borrowed', 'type' => 'borrowed', 'label' => 'Borrowed Amount', 'partyLabel' => 'Person Name', 'paidLabel' => 'Returned Amount', 'totalLabel' => 'Borrowed Amount'],
            ],
        ]);
    }
}
