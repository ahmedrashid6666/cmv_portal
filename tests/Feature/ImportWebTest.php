<?php

use App\Enums\Role;
use App\Models\Customer;
use App\Models\Reference;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\WorkbookImporter;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function uploadedWorkbook(): UploadedFile
{
    $ss = new Spreadsheet;
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
    $files = Storage::disk('local')->files('imports');
    expect($files)->not->toBeEmpty();

    $this->actingAs($admin)
        ->post(route('import.commit'), ['token' => $files[0]])
        ->assertRedirect(route('operations.index', ['type' => 'transactions']));

    expect(Transaction::count())->toBe(1)
        ->and((float) Transaction::first()->total_amount)->toBe(280.0);
});

it('imports when a customer/reference/vehicle was previously soft-deleted', function () {
    $admin = User::factory()->role(Role::ADMIN)->create();

    // These masters were created then deleted. Soft-delete keeps their row (and
    // its still-unique key) in the table; a naive firstOrCreate would try to
    // re-insert and hit the unique constraint, aborting the whole import.
    Customer::create(['name' => 'ESQUBE INDUSTRIES LLC'])->delete();
    Reference::create(['name' => 'JRY'])->delete();
    Vehicle::create(['number' => '3512RA'])->delete();

    $this->actingAs($admin)
        ->post(route('import.preview'), ['file' => uploadedWorkbook()])
        ->assertOk();

    $files = Storage::disk('local')->files('imports');

    $this->actingAs($admin)
        ->post(route('import.commit'), ['token' => $files[0]])
        ->assertRedirect(route('operations.index', ['type' => 'transactions']));

    // Import succeeded and reused the soft-deleted masters (no duplicates, restored).
    expect(Transaction::count())->toBe(1)
        ->and(Vehicle::withTrashed()->where('number', '3512RA')->count())->toBe(1)
        ->and(Vehicle::where('number', '3512RA')->exists())->toBeTrue()
        ->and(Customer::where('name', 'ESQUBE INDUSTRIES LLC')->exists())->toBeTrue()
        ->and(Reference::where('name', 'JRY')->exists())->toBeTrue();
});

it('reads the transaction date from the Date column, not the import day', function () {
    $admin = User::factory()->role(Role::ADMIN)->create();

    // Workbook with a real Date column (Excel serial) and a non-date sheet title,
    // mirroring the customer's FINAL.xlsx (sheet "280720", Date = 28-07-2026).
    $ss = new Spreadsheet;
    $sheet = $ss->getActiveSheet();
    $sheet->setTitle('280720');
    $headers = ['Sl No.', 'Invoice No', 'Date', 'Boe No.', 'Customer Name', 'Reference',
        'Vehicle No.', 'Customs Fees (CDR)', 'Other Gov.Fees', 'Profit', 'VAT 0%', 'Total Amount', 'Payment Mode'];
    foreach ($headers as $i => $h) {
        $sheet->setCellValue([$i + 1, 1], $h);
    }
    $serial = Date::PHPToExcel(new DateTime('2026-07-28'));
    $row = [1, '57484', $serial, '2010034251326', 'ASSA ABLOY', 'ROW-NOOH', '15901DXB', 295, 0, 50, 0, 345, 'Cash'];
    foreach ($row as $ci => $val) {
        $sheet->setCellValue([$ci + 1, 2], $val);
    }
    $path = tempnam(sys_get_temp_dir(), 'wb').'.xlsx';
    (new Xlsx($ss))->save($path);
    $file = new UploadedFile($path, 'FINAL.xlsx', null, null, true);

    $before = Storage::disk('local')->files('imports');
    $this->actingAs($admin)->post(route('import.preview'), ['file' => $file])->assertOk();
    $token = array_values(array_diff(Storage::disk('local')->files('imports'), $before))[0];

    $this->actingAs($admin)->post(route('import.commit'), ['token' => $token])
        ->assertRedirect(route('operations.index', ['type' => 'transactions']));

    expect(Transaction::first()->transaction_date->format('Y-m-d'))->toBe('2026-07-28');
});

it('forbids an accountant from importing', function () {
    $this->actingAs(User::factory()->role(Role::ACCOUNTANT)->create())
        ->post(route('import.preview'), ['file' => uploadedWorkbook()])
        ->assertForbidden();
});

it('shows a validation error (not a 500) when the workbook cannot be read', function () {
    $this->mock(WorkbookImporter::class)
        ->shouldReceive('parse')->andThrow(new RuntimeException('Could not open file for reading'));

    $this->actingAs(User::factory()->role(Role::ADMIN)->create())
        ->from(route('import.index'))
        ->post(route('import.preview'), ['file' => uploadedWorkbook()])
        ->assertRedirect(route('import.index'))
        ->assertSessionHasErrors('file');
});

it('flashes an error (not a 500) when a commit fails', function () {
    $mock = $this->mock(WorkbookImporter::class);
    $mock->shouldReceive('parse')->andReturn(['rows' => []]);
    $mock->shouldReceive('commit')->andThrow(new QueryException(
        'mysql', 'insert', [], new Exception("SQLSTATE[42S22]: Unknown column 'currency'")
    ));

    $admin = User::factory()->role(Role::ADMIN)->create();
    Storage::disk('local')->put('imports/x.xlsx', 'data');

    $this->actingAs($admin)
        ->from(route('import.index'))
        ->post(route('import.commit'), ['token' => 'imports/x.xlsx'])
        ->assertRedirect(route('import.index'))
        ->assertSessionHas('error');
});
