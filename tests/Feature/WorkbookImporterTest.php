<?php

use App\Models\Transaction;
use App\Services\WorkbookImporter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Builds a small fixture mirroring the real ACCOUNT WORKBOOK layout:
 * per-day sheet, header on row 2, expense "Amount" in a separate column
 * from "Total Amount", Com-1/Com-2 commission columns.
 */
function makeWorkbook(): string
{
    $ss = new Spreadsheet();
    $sheet = $ss->getActiveSheet();
    $sheet->setTitle('01-07-2026');

    $sheet->setCellValue('A1', 'DATE : 01-07-2026');
    $headers = ['Sl No.', 'Invoice No', 'Boe No.', 'Customer Name', 'Reference', 'Vehicle No.',
        'Customs Fees (CDR)', 'Other Gov.Fees', 'Profit', 'VAT 0%', 'Total Amount',
        'Payment Mode', 'Credit Amount', 'Expenses Details', '', '', '', 'Amount', 'Com-1 EX', 'Com-2', 'TOTAL'];
    foreach ($headers as $i => $h) {
        $sheet->setCellValue([$i + 1, 2], $h);
    }

    // row: customs 245, profit 35, total 280
    $r1 = [1, '56728', '2030026480926', 'ESQUBE INDUSTRIES LLC', 'JRY', '3512RA', 245, 0, 35, 0, 280, 'Cash', 0, '', '', '', '', '', '', '', 280];
    // row with expense (ZAJEL 27) + commission (25)
    $r2 = [2, '56732', '2010029464726', 'BIG BRANDS PERFUMES', 'ROW-ZNY', '63655DXB', 295, 0, 50, 0, 345, 'Cash', 0, 'ZAJEL PAYMENT', '', '', '', 27, 25, '', 397];
    // bad row: non-numeric customs
    $r3 = [3, '56999', '999', 'BADROW LLC', '', '', 'abc', 0, 10, 0, 10, 'Cash', 0, '', '', '', '', '', '', '', 10];

    foreach ([$r1, $r2, $r3] as $ri => $row) {
        foreach ($row as $ci => $val) {
            if ($val !== '') {
                $sheet->setCellValue([$ci + 1, 3 + $ri], $val);
            }
        }
    }

    $path = tempnam(sys_get_temp_dir(), 'wb').'.xlsx';
    (new Xlsx($ss))->save($path);

    return $path;
}

it('parses rows, expense amount, commission and flags a bad numeric', function () {
    $preview = app(WorkbookImporter::class)->parse(makeWorkbook());

    expect($preview['rows'])->toHaveCount(3)
        ->and($preview['sheets'])->toContain('01-07-2026');

    $big = collect($preview['rows'])->firstWhere('invoice_no', '56732');
    expect($big['expense_desc'])->toBe('ZAJEL PAYMENT')
        ->and((float) $big['expense_amount'])->toBe(27.0)
        ->and((float) $big['commission_1'])->toBe(25.0)
        ->and((float) $big['customs_fees'])->toBe(295.0);

    // bad numeric customs flagged as an error and coerced to 0
    expect($preview['errors'])->not->toBeEmpty();
    $bad = collect($preview['rows'])->firstWhere('invoice_no', '56999');
    expect((float) $bad['customs_fees'])->toBe(0.0);
});

/**
 * Builds the newer "Sales Invoice Profit Report" layout: header on row 1, no
 * gov-fees column, "Net Amount" instead of "Total Amount", and two commission
 * columns both labelled plainly "COMMISION" (no Com-1/Com-2 suffix).
 */
function makeProfitReport(): string
{
    $ss = new Spreadsheet();
    $sheet = $ss->getActiveSheet();
    $sheet->setTitle('ProfitReport_28072026');

    $headers = ['Sl No.', 'Invoice No', 'Boe No.', 'Customer', 'Reference', 'Vehicle No.',
        'Customs', 'Profit', 'VAT', 'Net Amount', 'Payment Received', 'COMMISION', 'COMMISION'];
    foreach ($headers as $i => $h) {
        $sheet->setCellValue([$i + 1, 1], $h);
    }

    // single-commission row (30) and dual-commission row (20 + 55)
    $r1 = [1, '57445', "''2030029961826''", 'MAPEI CONSTRUCTION', 'AHMED', '72458DXB', 245, 25, 0, 270, 'CASH', 30, ''];
    $r2 = [6, '57450', "''2030029979426''", 'GP GLOBAL MAG L.L.C', 'STP', '1584HW', 245, 30, 0, 275, 'CASH', 20, 55];

    foreach ([$r1, $r2] as $ri => $row) {
        foreach ($row as $ci => $val) {
            if ($val !== '') {
                $sheet->setCellValue([$ci + 1, 2 + $ri], $val);
            }
        }
    }

    $path = tempnam(sys_get_temp_dir(), 'pr').'.xlsx';
    (new Xlsx($ss))->save($path);

    return $path;
}

it('imports commission from the profit-report layout (plain COMMISION headers)', function () {
    $importer = app(WorkbookImporter::class);
    $preview = $importer->parse(makeProfitReport());

    // both commission columns are picked up despite the identical header text
    $dual = collect($preview['rows'])->firstWhere('invoice_no', '57450');
    expect((float) $dual['commission_1'])->toBe(20.0)
        ->and((float) $dual['commission_2'])->toBe(55.0)
        ->and((float) $dual['customs_fees'])->toBe(245.0)
        ->and((float) $dual['profit'])->toBe(30.0);

    $importer->commit($preview);

    $t = Transaction::where('invoice_no', '57450')->with('commissions')->first();
    expect($t->commissions)->toHaveCount(2)
        ->and((float) $t->commissions->sum('amount'))->toBe(75.0)
        ->and((float) $t->total_amount)->toBe(275.0); // 245 customs + 30 profit, no gov

    $single = Transaction::where('invoice_no', '57445')->with('commissions')->first();
    expect($single->commissions)->toHaveCount(1)
        ->and((float) $single->commissions->first()->amount)->toBe(30.0);
});

it('flags rows already in the system as duplicates in the preview', function () {
    $importer = app(WorkbookImporter::class);
    $path = makeWorkbook();

    // first import — nothing is a duplicate yet
    $first = $importer->parse($path);
    expect($first['duplicateCount'])->toBe(0);
    $importer->commit($first);

    // re-parse the same file — now every invoice+date already exists
    $second = $importer->parse($path);
    expect($second['duplicateCount'])->toBeGreaterThan(0)
        ->and(collect($second['rows'])->firstWhere('invoice_no', '56732')['_duplicate'])->toBeTrue();
});

it('commits rows idempotently', function () {
    $importer = app(WorkbookImporter::class);
    $path = makeWorkbook();

    $result = $importer->commit($importer->parse($path));
    expect($result['created'])->toBe(3)
        ->and(Transaction::count())->toBe(3);

    // the commission row computes grand total 345 + 25 = 370
    $big = Transaction::where('invoice_no', '56732')->first();
    expect((float) $big->total_amount)->toBe(345.0)
        ->and((float) $big->grand_total)->toBe(370.0)
        ->and($big->expenses)->toHaveCount(1)
        ->and($big->commissions)->toHaveCount(1);

    // re-import creates nothing new
    $again = $importer->commit($importer->parse($path));
    expect($again['created'])->toBe(0)
        ->and(Transaction::count())->toBe(3);
});
