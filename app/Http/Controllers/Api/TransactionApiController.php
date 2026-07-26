<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Services\TransactionWriter;
use Illuminate\Http\Request;

class TransactionApiController extends Controller
{
    public function index(Request $request)
    {
        $txns = Transaction::with(['customer:id,name'])
            ->when($request->filled('from'), fn ($q) => $q->whereDate('transaction_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('transaction_date', '<=', $request->date('to')))
            ->latest('transaction_date')->latest('id')
            ->paginate(50);

        return TransactionResource::collection($txns);
    }

    public function show(Transaction $transaction)
    {
        return new TransactionResource($transaction->load(['customer', 'expenses', 'commissions']));
    }

    public function store(TransactionRequest $request, TransactionWriter $writer)
    {
        $data = $request->validated();
        $data['created_by'] = $request->user()->id;
        $t = $writer->create($data);

        return (new TransactionResource($t->load(['customer', 'expenses', 'commissions'])))
            ->response()->setStatusCode(201);
    }

    public function update(TransactionRequest $request, Transaction $transaction, TransactionWriter $writer)
    {
        $t = $writer->update($transaction, $request->validated());

        return new TransactionResource($t->load(['customer', 'expenses', 'commissions']));
    }

    public function destroy(Transaction $transaction)
    {
        $transaction->delete();

        return response()->json(['message' => 'Deleted.']);
    }
}
