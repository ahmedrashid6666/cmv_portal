<?php

namespace App\Http\Controllers;

use App\Models\LedgerEntry;
use App\Models\LedgerPayment;
use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class BulkPaymentController extends Controller
{
    private const TYPES = [
        'daily-credit' => [LedgerEntry::TYPE_CREDIT, 'Daily Credit', 'Bulk Payment'],
        'borrowed' => [LedgerEntry::TYPE_BORROWED, 'Borrowed Amount', 'Bulk Return'],
    ];

    private function meta(string $slug): array
    {
        abort_unless(isset(self::TYPES[$slug]), 404);
        [$type, $moduleLabel, $actionLabel] = self::TYPES[$slug];

        return compact('slug', 'type', 'moduleLabel', 'actionLabel');
    }

    public function index(Request $request, string $slug)
    {
        $meta = $this->meta($slug);
        $search = $request->string('search')->trim()->value();

        $entries = [];
        if ($search !== '') {
            $entries = LedgerEntry::ofType($meta['type'])
                ->where('status', '!=', 'returned')
                ->where(fn ($q) => $q->where('party_name', 'like', "%{$search}%")->orWhere('reference', 'like', "%{$search}%"))
                ->orderBy('entry_date')->orderBy('id')   // oldest first (FIFO order)
                ->get()
                ->map(fn ($e) => [
                    'id' => $e->id,
                    'entry_date' => $e->entry_date->format('Y-m-d'),
                    'party_name' => $e->party_name,
                    'reference' => $e->reference,
                    'vehicle_number' => $e->vehicle_number,
                    'total_amount' => (float) $e->total_amount,
                    'paid_amount' => (float) $e->paid_amount,
                    'balance_amount' => (float) $e->balance_amount,
                    'status' => $e->status,
                ]);
        }

        return Inertia::render('BulkPayment/Index', [
            'meta' => $meta,
            'filters' => ['search' => $search],
            'entries' => $entries,
            'paymentMethods' => PaymentMethod::whereIn('type', ['cash', 'bank'])->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, string $slug)
    {
        $meta = $this->meta($slug);

        $data = $request->validate([
            'mode' => ['required', Rule::in(['fifo', 'manual'])],
            'payment_date' => ['required', 'date'],
            'payment_method_id' => ['nullable', 'exists:payment_methods,id'],
            'note' => ['nullable', 'string', 'max:255'],
            'entry_ids' => ['required', 'array', 'min:1'],
            'entry_ids.*' => ['integer'],
            'amount' => ['nullable', 'numeric', 'min:0'],                 // FIFO total
            'allocations' => ['nullable', 'array'],                        // manual: {id: amount}
        ]);

        // Selected entries, oldest first, still open, of this type.
        $entries = LedgerEntry::ofType($meta['type'])
            ->whereIn('id', $data['entry_ids'])
            ->where('status', '!=', 'returned')
            ->orderBy('entry_date')->orderBy('id')
            ->get();

        if ($entries->isEmpty()) {
            return back()->withErrors(['bulk' => 'No open entries were selected.']);
        }

        // Build per-entry allocation amounts.
        $alloc = [];
        if ($data['mode'] === 'fifo') {
            $remaining = round((float) ($data['amount'] ?? 0), 2);
            if ($remaining <= 0) {
                return back()->withErrors(['amount' => 'Enter an amount to distribute.']);
            }
            foreach ($entries as $e) {
                if ($remaining <= 0) {
                    break;
                }
                $take = min($remaining, (float) $e->balance_amount);
                if ($take > 0) {
                    $alloc[$e->id] = round($take, 2);
                    $remaining = round($remaining - $take, 2);
                }
            }
            $leftover = $remaining;
        } else {
            foreach ($entries as $e) {
                $amt = round((float) ($data['allocations'][$e->id] ?? 0), 2);
                if ($amt <= 0) {
                    continue;
                }
                if ($amt > (float) $e->balance_amount + 0.001) {
                    return back()->withErrors(['bulk' => "Allocation for '{$e->party_name}' exceeds its balance."]);
                }
                $alloc[$e->id] = $amt;
            }
            $leftover = 0;
        }

        if (empty($alloc)) {
            return back()->withErrors(['bulk' => 'Nothing to allocate.']);
        }

        $applied = 0;
        $count = 0;
        DB::transaction(function () use ($entries, $alloc, $data, $request, &$applied, &$count) {
            foreach ($entries as $e) {
                $amt = $alloc[$e->id] ?? 0;
                if ($amt <= 0) {
                    continue;
                }
                LedgerPayment::create([
                    'ledger_entry_id' => $e->id,
                    'payment_date' => $data['payment_date'],
                    'amount' => $amt,
                    'payment_method_id' => $data['payment_method_id'] ?? null,
                    'note' => $data['note'] ?? null,
                    'created_by' => $request->user()->id,
                ]);

                $e->paid_amount = round((float) $e->paid_amount + $amt, 2);
                // stamp the settlement date with the payment date when it clears
                if ($e->paid_amount >= (float) $e->total_amount && ! $e->return_date) {
                    $e->return_date = $data['payment_date'];
                }
                $e->save();       // model recomputes balance + status
                $applied = round($applied + $amt, 2);
                $count++;
            }
        });

        $msg = number_format($applied, 2)." applied across {$count} entr(y/ies).";
        if (! empty($leftover) && $leftover > 0) {
            $msg .= ' AED '.number_format($leftover, 2).' left unallocated (exceeded outstanding balance).';
        }

        return redirect()->route('ledger.index', $meta['slug'])->with('success', $meta['actionLabel'].': AED '.$msg);
    }
}
