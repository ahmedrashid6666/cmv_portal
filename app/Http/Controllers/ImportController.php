<?php

namespace App\Http\Controllers;

use App\Exceptions\WorkbookFormatException;
use App\Services\WorkbookImporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ImportController extends Controller
{
    public function index()
    {
        return Inertia::render('Import/Index');
    }

    public function preview(Request $request, WorkbookImporter $importer)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xlsm,csv,xls', 'max:20480'],
        ]);

        $path = $request->file('file')->store('imports', 'local');
        $absolute = Storage::disk('local')->path($path);

        try {
            $preview = $importer->parse($absolute);
        } catch (WorkbookFormatException $e) {
            Storage::disk('local')->delete($path);
            Log::warning('Excel import wrong format', ['error' => $e->getMessage()]);

            // Show the exact, user-facing format error verbatim.
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            Log::warning('Excel import preview failed', ['error' => $e->getMessage()]);

            throw ValidationException::withMessages([
                'file' => 'Could not read this file: '.$this->friendly($e),
            ]);
        }

        return Inertia::render('Import/Index', [
            'preview' => [
                'token' => $path, // reference the stored file for commit
                'sheets' => $preview['sheets'],
                'rowCount' => count($preview['rows']),
                'duplicateCount' => $preview['duplicateCount'] ?? 0,
                'newCount' => count($preview['rows']) - ($preview['duplicateCount'] ?? 0),
                'errors' => $preview['errors'],
                'newCustomers' => $preview['newCustomers'],
                'newReferences' => $preview['newReferences'],
                'newVehicles' => $preview['newVehicles'],
                'sample' => array_slice($preview['rows'], 0, 25),
            ],
        ]);
    }

    public function commit(Request $request, WorkbookImporter $importer)
    {
        $request->validate(['token' => ['required', 'string']]);
        $path = $request->string('token')->value();

        abort_unless(Storage::disk('local')->exists($path), 404, 'Upload expired, please re-upload.');

        $absolute = Storage::disk('local')->path($path);

        try {
            $result = $importer->commit($importer->parse($absolute), $request->user()->id);
        } catch (WorkbookFormatException $e) {
            Log::warning('Excel import wrong format', ['error' => $e->getMessage()]);

            return back()->with('error', 'Import failed — nothing was saved. '.$e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Excel import commit failed', ['error' => $e->getMessage()]);

            return back()->with('error', 'Import failed — nothing was saved. '.$this->friendly($e));
        }

        Storage::disk('local')->delete($path);

        return redirect()->route('operations.index', ['type' => 'transactions'])->with(
            'success',
            "Imported {$result['created']} transaction(s), skipped {$result['skipped']} duplicate(s). "
            ."Created {$result['customers']} customers, {$result['references']} references, {$result['vehicles']} vehicles."
        );
    }

    /**
     * Turn a raw exception into a message that helps the user, not a stack trace.
     */
    private function friendly(\Throwable $e): string
    {
        $msg = $e->getMessage();

        if (str_contains($msg, 'Unknown column') || str_contains($msg, 'SQLSTATE')) {
            return 'the database is missing a recent update. Please run the pending migrations (php artisan migrate) and try again.';
        }
        if (stripos($msg, 'zip') !== false || stripos($msg, 'not recognised') !== false || stripos($msg, 'could not open') !== false) {
            return 'the file appears to be corrupt or is not a valid .xlsx/.xlsm/.csv workbook.';
        }

        return \Illuminate\Support\Str::limit($msg, 200);
    }
}
