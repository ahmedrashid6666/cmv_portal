<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use App\Models\BankEntry;
use App\Services\BalanceService;
use App\Services\BankService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BankAccountController extends Controller
{
    public function index(BankService $banks, BalanceService $balances)
    {
        $rows = $banks->balances();

        return Inertia::render('Banks/Accounts', [
            'banks' => $rows,
            'totals' => [
                'opening' => round(array_sum(array_column($rows, 'opening')), 2),
                'customs_paid' => round(array_sum(array_column($rows, 'customs_paid')), 2),
                'gov_paid' => round(array_sum(array_column($rows, 'gov_paid')), 2),
                'balance' => round(array_sum(array_column($rows, 'balance')), 2),
            ],
            // The dashboard bank figure also includes bank sales / repayments that
            // aren't tied to a specific account — surface the gap transparently.
            'combinedBankBalance' => (float) $balances->bankBalance(),
        ]);
    }

    public function statement(Request $request, Bank $bank, BankService $banks)
    {
        return Inertia::render('Banks/Statement', [
            'statement' => $banks->statement($bank, $request->only(['from', 'to'])),
            'filters' => $request->only(['from', 'to']),
        ]);
    }

    public function exportStatement(Request $request, Bank $bank, BankService $banks)
    {
        $statement = $banks->statement($bank, $request->only(['from', 'to']));
        $format = $request->string('format')->value();

        return $format === 'pdf'
            ? $this->statementPdf($statement)
            : $this->statementXlsx($statement);
    }

    private function statementPdf(array $statement)
    {
        $pdf = Pdf::loadView('bank-statement.pdf', ['statement' => $statement]);

        return $pdf->download('bank-statement-'.Str::slug($statement['bank']['name']).'.pdf');
    }

    private function statementXlsx(array $statement): StreamedResponse
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setCellValue('A1', 'Bank Statement — '.$statement['bank']['name']);

        $columns = ['Date', 'Description', 'Invoice', 'In', 'Out', 'Balance'];
        foreach ($columns as $i => $col) {
            $sheet->setCellValue([$i + 1, 3], $col);
        }

        $sheet->setCellValue([1, 4], 'Opening balance');
        $sheet->setCellValue([6, 4], $statement['opening']);

        foreach ($statement['rows'] as $r => $row) {
            $rowNum = $r + 5;
            $sheet->setCellValue([1, $rowNum], $row['date']);
            $sheet->setCellValue([2, $rowNum], $row['description']);
            $sheet->setCellValue([3, $rowNum], $row['ref'] ?? '—');
            $sheet->setCellValue([4, $rowNum], $row['debit'] ?: null);
            $sheet->setCellValue([5, $rowNum], $row['credit'] ?: null);
            $sheet->setCellValue([6, $rowNum], $row['balance']);
        }

        $totalsRow = count($statement['rows']) + 6;
        $sheet->setCellValue([1, $totalsRow], 'TOTALS');
        $sheet->setCellValue([2, $totalsRow], 'Total In: '.number_format($statement['total_in'], 2));
        $sheet->setCellValue([3, $totalsRow], 'Total Out: '.number_format($statement['total_out'], 2));
        $sheet->setCellValue([4, $totalsRow], 'Closing: '.number_format($statement['closing'], 2));

        $filename = 'bank-statement-'.Str::slug($statement['bank']['name']).'.xlsx';

        return response()->streamDownload(function () use ($ss) {
            (new Xlsx($ss))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Record a manual money movement (In = deposit, Out = withdrawal) on a bank.
     * Exactly one of the In / Out amounts must be positive.
     */
    public function storeEntry(Request $request, Bank $bank)
    {
        $data = $request->validate([
            'entry_date' => ['required', 'date'],
            'item' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'in' => ['nullable', 'numeric', 'min:0'],
            'out' => ['nullable', 'numeric', 'min:0'],
        ]);

        $in = (float) ($data['in'] ?? 0);
        $out = (float) ($data['out'] ?? 0);

        if (($in > 0) === ($out > 0)) {
            throw ValidationException::withMessages([
                'amount' => 'Enter an amount in either In or Out — not both, and not zero.',
            ]);
        }

        $bank->entries()->create([
            'entry_date' => $data['entry_date'],
            'item' => $data['item'],
            'description' => $data['description'] ?? null,
            'direction' => $in > 0 ? 'in' : 'out',
            'amount' => $in > 0 ? $in : $out,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Bank entry added.');
    }

    /**
     * Update a manual money movement. Same In/Out mutual-exclusivity rule as storeEntry.
     */
    public function updateEntry(Request $request, BankEntry $entry)
    {
        $data = $request->validate([
            'entry_date' => ['required', 'date'],
            'item' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'in' => ['nullable', 'numeric', 'min:0'],
            'out' => ['nullable', 'numeric', 'min:0'],
        ]);

        $in = (float) ($data['in'] ?? 0);
        $out = (float) ($data['out'] ?? 0);

        if (($in > 0) === ($out > 0)) {
            throw ValidationException::withMessages([
                'amount' => 'Enter an amount in either In or Out — not both, and not zero.',
            ]);
        }

        $entry->update([
            'entry_date' => $data['entry_date'],
            'item' => $data['item'],
            'description' => $data['description'] ?? null,
            'direction' => $in > 0 ? 'in' : 'out',
            'amount' => $in > 0 ? $in : $out,
        ]);

        return back()->with('success', 'Bank entry updated.');
    }

    public function destroyEntry(BankEntry $entry)
    {
        $entry->delete();

        return back()->with('success', 'Bank entry removed.');
    }
}
