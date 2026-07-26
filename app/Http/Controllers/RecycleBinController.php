<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Inertia\Inertia;

class RecycleBinController extends Controller
{
    public function index()
    {
        $trashed = Transaction::onlyTrashed()
            ->with(['customer:id,name', 'paymentMethod:id,name'])
            ->latest('deleted_at')
            ->paginate(20)
            ->through(fn ($t) => [
                'id' => $t->id,
                'deleted_at' => $t->deleted_at?->format('Y-m-d H:i'),
                'transaction_date' => $t->transaction_date?->format('Y-m-d'),
                'invoice_no' => $t->invoice_no,
                'customer' => $t->customer?->name,
                'method' => $t->paymentMethod?->name,
                'grand_total' => (float) $t->grand_total,
            ]);

        return Inertia::render('Bin/Index', ['trashed' => $trashed]);
    }

    public function restore(int $id)
    {
        Transaction::onlyTrashed()->findOrFail($id)->restore();

        return back()->with('success', 'Transaction restored.');
    }

    public function forceDelete(int $id)
    {
        Transaction::onlyTrashed()->findOrFail($id)->forceDelete();

        return back()->with('success', 'Transaction permanently deleted.');
    }
}
