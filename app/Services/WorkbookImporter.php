<?php

namespace App\Services;

use App\Exceptions\WorkbookFormatException;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Reference;
use App\Models\Transaction;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * Parses and imports the CMV "ACCOUNT WORKBOOK" (.xlsx/.xlsm/.csv).
 *
 * The workbook keeps one worksheet per day; each data row is one shipment.
 * We locate the header row by keyword, map columns to fields, and build a
 * preview the user confirms before anything is written. Import is
 * idempotent on (invoice_no, transaction_date).
 */
class WorkbookImporter
{
    /** Header keyword => internal field. */
    private const HEADER_MAP = [
        'invoice' => 'invoice_no',
        'date' => 'row_date',
        'boe' => 'boe_no',
        'customer' => 'customer',
        'reference' => 'reference',
        'vehicle' => 'vehicle',
        'customs' => 'customs_fees',
        'gov' => 'gov_fees',
        'profit' => 'profit',
        'vat' => 'vat',
        'grand total' => 'grand_total',
        'total amount' => 'total_amount',
        'payment' => 'payment_mode',
        'credit' => 'credit_amount',
        'expenses details' => 'expense_desc',
        'expense details' => 'expense_desc',
        // Commission columns ("Com-1"/"Com-2" or plain "COMMISION") are matched
        // separately in mapColumns() so both spellings and duplicate headers work.
    ];

    /**
     * Parse a spreadsheet into a preview structure without writing anything.
     *
     * @return array{rows: array<int, array<string, mixed>>, errors: array<int, string>, newCustomers: array<int, string>, newReferences: array<int, string>, newVehicles: array<int, string>, sheets: array<int, string>}
     */
    public function parse(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $rows = [];
        $errors = [];
        $newCustomers = [];
        $newReferences = [];
        $newVehicles = [];
        $sheets = [];

        // withTrashed: soft-deleted masters still hold their unique key and are
        // reused on commit, so they must not be counted as "new" in the preview.
        $existingCustomers = Customer::withTrashed()->pluck('name')->map(fn ($n) => mb_strtolower(trim($n)))->all();
        $existingReferences = Reference::withTrashed()->pluck('name')->map(fn ($n) => mb_strtolower(trim($n)))->all();
        $existingVehicles = Vehicle::withTrashed()->pluck('number')->map(fn ($n) => mb_strtolower(trim($n)))->all();

        // Preload existing (invoice_no + date) so we can flag duplicates in the preview.
        $existingInvoiceKeys = [];
        Transaction::query()
            ->whereNotNull('invoice_no')
            ->get(['invoice_no', 'transaction_date'])
            ->each(function ($t) use (&$existingInvoiceKeys) {
                $existingInvoiceKeys[trim($t->invoice_no).'|'.$t->transaction_date->format('Y-m-d')] = true;
            });
        $duplicateCount = 0;
        $firstSheetHeaders = null; // for a helpful "wrong format" message

        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            $data = $sheet->toArray(null, true, false, false);
            if ($firstSheetHeaders === null) {
                foreach ($data as $r) {
                    $cells = array_values(array_filter(array_map(fn ($c) => trim((string) $c), $r), fn ($c) => $c !== ''));
                    if ($cells) {
                        $firstSheetHeaders = $cells;
                        break;
                    }
                }
            }
            $headerRow = $this->findHeaderRow($data);
            if ($headerRow === null) {
                continue; // not a data sheet (e.g. MONTH END CALCULATION)
            }
            $sheets[] = $sheet->getTitle();
            $map = $this->mapColumns($data[$headerRow]);
            $sheetDate = $this->sheetDate($sheet->getTitle());

            for ($i = $headerRow + 1; $i < count($data); $i++) {
                $raw = $data[$i];
                $parsed = $this->parseRow($raw, $map, $sheetDate);
                if ($parsed === null) {
                    continue; // blank / total row
                }

                $rowNo = $i + 1;

                // A real shipment row has at least one numeric money value.
                // Rows where customs+gov+profit are all non-numeric are header
                // or label rows of embedded sub-tables — skip them silently.
                $hasMoney = is_numeric($parsed['customs_fees'])
                    || is_numeric($parsed['gov_fees'])
                    || is_numeric($parsed['profit']);
                if (! $hasMoney) {
                    continue;
                }

                foreach (['customs_fees', 'gov_fees', 'profit'] as $numeric) {
                    $value = $parsed[$numeric];
                    if ($value === null || $value === '') {
                        $parsed[$numeric] = 0; // blank cell → zero, no error
                    } elseif (! is_numeric($value)) {
                        $errors[] = "Sheet '{$sheet->getTitle()}' row {$rowNo}: '{$numeric}' value \"{$value}\" is not a number — treated as 0.";
                        $parsed[$numeric] = 0;
                    }
                }

                // collect new masters (case-insensitive, de-duplicated)
                $this->collectNew($parsed['customer'], $existingCustomers, $newCustomers);
                if ($parsed['reference']) {
                    $this->collectNew($parsed['reference'], $existingReferences, $newReferences);
                }
                if ($parsed['vehicle']) {
                    $this->collectNew($parsed['vehicle'], $existingVehicles, $newVehicles);
                }

                $parsed['_sheet'] = $sheet->getTitle();
                $parsed['_row'] = $rowNo;
                $parsed['_duplicate'] = $parsed['invoice_no']
                    && isset($existingInvoiceKeys[trim($parsed['invoice_no']).'|'.$parsed['transaction_date']]);
                if ($parsed['_duplicate']) {
                    $duplicateCount++;
                }
                $rows[] = $parsed;
            }
        }

        $expected = 'Expected headers: Invoice No, Boe No, Customer Name, Reference, Vehicle No, Customs Fees (CDR), Other Gov.Fees, Profit, VAT, Total Amount, Commission, Grand Total, Credit Amount, Payment.';

        if (empty($sheets)) {
            $found = $firstSheetHeaders
                ? '"'.implode('", "', array_slice($firstSheetHeaders, 0, 15)).'"'
                : '(no readable rows were found in the file)';

            throw new WorkbookFormatException(
                'Wrong file format: no data table was detected. The importer needs a header row that contains at least a "Customer" column and an "Invoice" column. '
                ."Columns found in the first sheet: {$found}. {$expected}"
            );
        }

        if (empty($rows)) {
            throw new WorkbookFormatException(
                "Wrong file format: the header row was found on sheet '".$sheets[0]."', but no valid data rows could be read. "
                .'Each row needs a Customer name and at least one numeric money value (Customs Fees, Other Gov.Fees or Profit). '.$expected
            );
        }

        return compact('rows', 'errors', 'newCustomers', 'newReferences', 'newVehicles', 'sheets', 'duplicateCount');
    }

    /**
     * Commit a parsed preview to the database. Idempotent on (invoice_no, date).
     *
     * @param  array{rows: array<int, array<string, mixed>>}  $preview
     * @return array{created: int, skipped: int, customers: int, references: int, vehicles: int}
     */
    public function commit(array $preview, ?int $userId = null): array
    {
        $created = 0;
        $skipped = 0;
        $customers = 0;
        $references = 0;
        $vehicles = 0;

        $writer = app(TransactionWriter::class);
        $defaultMethod = PaymentMethod::firstOrCreate(['name' => 'Cash'], ['type' => 'cash']);

        DB::transaction(function () use ($preview, $writer, $userId, $defaultMethod, &$created, &$skipped, &$customers, &$references, &$vehicles) {
            foreach ($preview['rows'] as $row) {
                if (! $row['customer']) {
                    $skipped++;

                    continue;
                }

                $customer = $this->resolveMaster(Customer::class, 'name', trim($row['customer']), $customers);

                $reference = null;
                if ($row['reference']) {
                    $reference = $this->resolveMaster(Reference::class, 'name', trim($row['reference']), $references);
                }

                $vehicle = null;
                if ($row['vehicle']) {
                    $vehicle = $this->resolveMaster(Vehicle::class, 'number', trim($row['vehicle']), $vehicles);
                }

                // idempotency: skip if same invoice+date already imported
                $exists = Transaction::query()
                    ->where('transaction_date', $row['transaction_date'])
                    ->when($row['invoice_no'], fn ($q) => $q->where('invoice_no', $row['invoice_no']))
                    ->when(! $row['invoice_no'], fn ($q) => $q->whereNull('invoice_no')->where('customer_id', $customer->id)->where('boe_no', $row['boe_no']))
                    ->exists();
                if ($exists) {
                    $skipped++;

                    continue;
                }

                $method = $this->resolveMethod($row['payment_mode']) ?? $defaultMethod;

                $expenses = [];
                if ($row['expense_desc'] || (float) $row['expense_amount'] > 0) {
                    $expenses[] = ['description' => $row['expense_desc'], 'amount' => (float) $row['expense_amount']];
                }
                $commissions = [];
                foreach (['commission_1', 'commission_2'] as $ci => $ckey) {
                    if ((float) ($row[$ckey] ?? 0) > 0) {
                        $commissions[] = ['label' => 'Com-'.($ci + 1), 'amount' => (float) $row[$ckey], 'type' => 'charged_to_customer'];
                    }
                }

                $writer->create([
                    'transaction_date' => $row['transaction_date'],
                    'invoice_no' => $row['invoice_no'],
                    'boe_no' => $row['boe_no'],
                    'customer_id' => $customer->id,
                    'reference_id' => $reference?->id,
                    'vehicle_number' => $vehicle?->number,
                    'customs_fees' => (float) $row['customs_fees'],
                    'gov_fees' => (float) $row['gov_fees'],
                    'profit' => (float) $row['profit'],
                    'vat_rate' => 0,
                    'payment_method_id' => $method->id,
                    'credit_amount' => (float) ($row['credit_amount'] ?? 0),
                    'created_by' => $userId,
                    'expenses' => $expenses,
                    'commissions' => $commissions,
                ]);
                $created++;
            }
        });

        return compact('created', 'skipped', 'customers', 'references', 'vehicles');
    }

    /**
     * @param  array<int, array<int, mixed>>  $data
     */
    private function findHeaderRow(array $data): ?int
    {
        foreach ($data as $i => $row) {
            $joined = mb_strtolower(implode(' ', array_map(fn ($c) => (string) $c, $row)));
            if (str_contains($joined, 'customer') && str_contains($joined, 'invoice')) {
                return $i;
            }
            if ($i > 8) {
                break; // header is always near the top
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $headerCells
     * @return array<string, int> field => column index
     */
    private function mapColumns(array $headerCells): array
    {
        $map = [];
        foreach ($headerCells as $idx => $cell) {
            $norm = mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $cell)));
            if ($norm === '') {
                continue;
            }

            // "Amount" (expense) is a bare column, distinct from "Total Amount"
            // and "Credit Amount" — match it exactly so substrings don't collide.
            if ($norm === 'amount' && ! isset($map['expense_amount'])) {
                $map['expense_amount'] = $idx;

                continue;
            }

            // Commission columns. Workbooks label them either "Com-1"/"Com-2" or
            // just "COMMISION"/"COMMISSION" (sometimes two identical headers side
            // by side). Assign the first commission column to Com-1, the next to
            // Com-2, regardless of the exact spelling.
            if (str_contains($norm, 'commis') || preg_match('/\bcom-?\s*[12]?\b/', $norm)) {
                if (! isset($map['commission_1'])) {
                    $map['commission_1'] = $idx;
                } elseif (! isset($map['commission_2'])) {
                    $map['commission_2'] = $idx;
                }

                continue;
            }

            foreach (self::HEADER_MAP as $needle => $field) {
                if (str_contains($norm, $needle) && ! isset($map[$field])) {
                    $map[$field] = $idx;
                }
            }
        }

        return $map;
    }

    /**
     * @param  array<int, mixed>  $raw
     * @param  array<string, int>  $map
     * @return array<string, mixed>|null
     */
    private function parseRow(array $raw, array $map, ?string $sheetDate): ?array
    {
        $get = fn (string $field) => isset($map[$field]) ? ($raw[$map[$field]] ?? null) : null;

        $customer = trim((string) $get('customer'));
        $customs = $get('customs_fees');
        $profit = $get('profit');

        // The customer name is the reliable signal for a real shipment row.
        // Blank / summary / stray rows have no customer and are skipped silently.
        if ($customer === '' || mb_strtolower($customer) === 'total') {
            return null;
        }
        // Repeated header blocks mid-sheet (some workbooks restart the table).
        if (str_contains(mb_strtolower($customer), 'customer')) {
            return null;
        }

        // The real date lives in the row's "Date" column when present; fall back
        // to the per-day sheet title, then to today only as a last resort.
        return [
            'transaction_date' => $this->rowDate($get('row_date')) ?? $sheetDate ?? Carbon::today()->toDateString(),
            'invoice_no' => $this->str($get('invoice_no')),
            'boe_no' => $this->cleanBoe($get('boe_no')),
            'customer' => $customer,
            'reference' => $this->str($get('reference')) === '-' ? null : $this->str($get('reference')),
            'vehicle' => $this->str($get('vehicle')),
            'customs_fees' => $customs,
            'gov_fees' => $get('gov_fees'),
            'profit' => $profit,
            'vat' => is_numeric($get('vat')) ? $get('vat') : 0,
            'total_amount' => is_numeric($get('total_amount')) ? $get('total_amount') : null,
            'grand_total' => is_numeric($get('grand_total')) ? $get('grand_total') : null,
            'credit_amount' => is_numeric($get('credit_amount')) ? $get('credit_amount') : 0,
            'payment_mode' => $this->str($get('payment_mode')),
            'expense_desc' => $this->str($get('expense_desc')),
            'expense_amount' => is_numeric($get('expense_amount')) ? $get('expense_amount') : 0,
            'commission_1' => is_numeric($get('commission_1')) ? $get('commission_1') : 0,
            'commission_2' => is_numeric($get('commission_2')) ? $get('commission_2') : 0,
        ];
    }

    /**
     * Resolve (or create) a soft-deleting master by its unique column.
     *
     * These masters (customers/references/vehicles) use soft-deletes, so a
     * deleted row still occupies its unique key in the table. A plain
     * firstOrCreate ignores soft-deleted rows and would try to re-insert the
     * key — hitting the unique constraint and aborting the import. Searching
     * withTrashed reuses the existing row, restoring it when it was deleted.
     *
     * @param  class-string<Model>  $model
     */
    private function resolveMaster(string $model, string $column, string $value, int &$created)
    {
        $record = $model::withTrashed()->firstOrCreate([$column => $value]);

        if ($record->wasRecentlyCreated) {
            $created++;
        } elseif ($record->trashed()) {
            $record->restore(); // deleted master reused by a new import
        }

        return $record;
    }

    private function resolveMethod(?string $name): ?PaymentMethod
    {
        if (! $name) {
            return null;
        }

        return PaymentMethod::whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])->first();
    }

    /**
     * Parse a cell from the "Date" column into a Y-m-d string.
     *
     * With formatData off the reader returns Excel dates as serial numbers, so
     * numeric values are converted via the Excel epoch; textual dates are parsed
     * with the day-first formats these workbooks use.
     */
    private function rowDate(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }

        if (is_numeric($v)) {
            try {
                return Date::excelToDateTimeObject((float) $v)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        $s = trim((string) $v);
        foreach (['d-m-Y', 'd/m/Y', 'Y-m-d', 'd-m-y', 'd.m.Y', 'd M Y', 'd-M-Y'] as $fmt) {
            try {
                return Carbon::createFromFormat($fmt, $s)->toDateString();
            } catch (\Throwable) {
                continue;
            }
        }

        try {
            return Carbon::parse($s)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function sheetDate(string $title): ?string
    {
        // sheet titles like "01-07-2026"
        $t = trim($title);
        foreach (['d-m-Y', 'd/m/Y', 'Y-m-d', 'd-m-y'] as $fmt) {
            try {
                return Carbon::createFromFormat($fmt, $t)->toDateString();
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function collectNew(string $value, array $existing, array &$bucket): void
    {
        $key = mb_strtolower(trim($value));
        if ($key === '' || $key === '-') {
            return;
        }
        if (! in_array($key, $existing, true) && ! in_array($value, $bucket, true)) {
            $bucket[] = trim($value);
        }
    }

    private function str(mixed $v): ?string
    {
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }

    private function cleanBoe(mixed $v): ?string
    {
        $s = trim((string) $v, " '\"");

        return $s === '' ? null : $s;
    }
}
