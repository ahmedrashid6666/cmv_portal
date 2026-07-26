<?php

namespace App\Http\Controllers;

use App\Services\WorkbookImporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
        $preview = $importer->parse($absolute);

        return Inertia::render('Import/Index', [
            'preview' => [
                'token' => $path, // reference the stored file for commit
                'sheets' => $preview['sheets'],
                'rowCount' => count($preview['rows']),
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
        $result = $importer->commit($importer->parse($absolute), $request->user()->id);
        Storage::disk('local')->delete($path);

        return redirect()->route('transactions.index')->with(
            'success',
            "Imported {$result['created']} transaction(s), skipped {$result['skipped']} duplicate(s). "
            ."Created {$result['customers']} customers, {$result['references']} references, {$result['vehicles']} vehicles."
        );
    }
}
