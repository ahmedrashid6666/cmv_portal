<?php

use App\Enums\Role;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function uploadedWorkbook(): UploadedFile
{
    $ss = new Spreadsheet();
    $sheet = $ss->getActiveSheet();
    $sheet->setTitle('01-07-2026');
    $headers = ['Sl No.', 'Invoice No', 'Boe No.', 'Customer Name', 'Reference', 'Vehicle No.',
        'Customs Fees (CDR)', 'Other Gov.Fees', 'Profit', 'VAT 0%', 'Total Amount',
        'Payment Mode', 'Credit Amount', 'Expenses Details', '', '', '', 'Amount', 'Com-1 EX', 'Com-2', 'TOTAL'];
    foreach ($headers as $i => $h) {
        $sheet->setCellValue([$i + 1, 2], $h);
    }
    $row = [1, '56728', '2030026480926', 'ESQUBE INDUSTRIES LLC', 'JRY', '3512RA', 245, 0, 35, 0, 280, 'Cash', 0, '', '', '', '', '', '', '', 280];
    foreach ($row as $ci => $val) {
        if ($val !== '') {
            $sheet->setCellValue([$ci + 1, 3], $val);
        }
    }
    $path = tempnam(sys_get_temp_dir(), 'wb').'.xlsx';
    (new Xlsx($ss))->save($path);

    return new UploadedFile($path, 'workbook.xlsx', null, null, true);
}

it('previews an uploaded workbook and then commits it', function () {
    $admin = User::factory()->role(Role::ADMIN)->create();

    // preview
    $this->actingAs($admin)
        ->post(route('import.preview'), ['file' => uploadedWorkbook()])
        ->assertOk();

    // grab the stored token from the newest import file and commit
    $files = \Illuminate\Support\Facades\Storage::disk('local')->files('imports');
    expect($files)->not->toBeEmpty();

    $this->actingAs($admin)
        ->post(route('import.commit'), ['token' => $files[0]])
        ->assertRedirect(route('transactions.index'));

    expect(Transaction::count())->toBe(1)
        ->and((float) Transaction::first()->total_amount)->toBe(280.0);
});

it('forbids an accountant from importing', function () {
    $this->actingAs(User::factory()->role(Role::ACCOUNTANT)->create())
        ->post(route('import.preview'), ['file' => uploadedWorkbook()])
        ->assertForbidden();
});
