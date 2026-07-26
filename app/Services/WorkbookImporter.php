<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Reference;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

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
        'boe' => 'boe_no',
        'customer' => 'customer',
        'reference' => 'reference',
        'vehicle' => 'vehicle',
        'customs' => 'customs_fees',
        'gov' => 'gov_fees',
        'profit' => 'profit',
        'vat' => 'vat',
        'total amount' => 'total_amount',
        'payment' => 'payment_mode',
        'credit' => 'credit_amount',
        'expenses details' => 'expense_desc',
        'expense details' => 'expense_desc',
        'com-1' => 'commission_1',
        'com-2' => 'commission_2',
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

        $existingCustomers = Customer::pluck('name')->map(fn ($n) => mb_strtolower(trim($n)))->all();
        $existingReferences = Reference::pluck('name')->map(fn ($n) => mb_strtolower(trim($n)))->all();
        $existingVehicles = Vehicle::pluck('number')->map(fn ($n) => mb_strtolower(trim($n)))->all();

        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            $data = $sheet->toArray(null, true, false, false);
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
                $rows[] = $parsed;
            }
        }

        return compact('rows', 'errors', 'newCustomers', 'newReferences', 'newVehicles', 'sheets');
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

                $customer = Customer::firstOrCreate(['name' => trim($row['customer'])]);
                if ($customer->wasRecentlyCreated) {
                    $customers++;
                }

                $reference = null;
                if ($row['reference']) {
                    $reference = Reference::firstOrCreate(['name' => trim($row['reference'])]);
                    if ($reference->wasRecentlyCreated) {
                        $references++;
                    }
                }

                $vehicle = null;
                if ($row['vehicle']) {
                    $vehicle = Vehicle::firstOrCreate(['number' => trim($row['vehicle'])]);
                    if ($vehicle->wasRecentlyCreated) {
                        $vehicles++;
                    }
                }

                // idempotency: skip if same invoice+date already imported
                $exists = \App\Models\Transaction::query()
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
                    'vehicle_id' => $vehicle?->id,
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
     * @return array<string, int>  field => column index
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

        return [
            'transaction_date' => $sheetDate ?? Carbon::today()->toDateString(),
            'invoice_no' => $this->str($get('invoice_no')),
            'boe_no' => $this->cleanBoe($get('boe_no')),
            'customer' => $customer,
            'reference' => $this->str($get('reference')) === '-' ? null : $this->str($get('reference')),
            'vehicle' => $this->str($get('vehicle')),
            'customs_fees' => $customs,
            'gov_fees' => $get('gov_fees'),
            'profit' => $profit,
            'credit_amount' => is_numeric($get('credit_amount')) ? $get('credit_amount') : 0,
            'payment_mode' => $this->str($get('payment_mode')),
            'expense_desc' => $this->str($get('expense_desc')),
            'expense_amount' => is_numeric($get('expense_amount')) ? $get('expense_amount') : 0,
            'commission_1' => is_numeric($get('commission_1')) ? $get('commission_1') : 0,
            'commission_2' => is_numeric($get('commission_2')) ? $get('commission_2') : 0,
        ];
    }

    private function resolveMethod(?string $name): ?PaymentMethod
    {
        if (! $name) {
            return null;
        }

        return PaymentMethod::whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])->first();
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
