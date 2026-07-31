<?php

namespace App\Http\Controllers;

use App\Support\MasterRegistry;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MasterController extends Controller
{
    public function index(Request $request, string $master)
    {
        $config = MasterRegistry::get($master);
        $model = $config['model'];

        $query = $model::query();
        if ($search = $request->string('search')->trim()->value()) {
            // Search across every text column (skip numeric ones like balances).
            $numberFields = collect($config['fields'])->where('type', 'number')->pluck('name')->all();
            $searchable = array_diff(array_keys($config['columns']), $numberFields);
            $query->where(function ($q) use ($searchable, $search) {
                foreach ($searchable as $col) {
                    $q->orWhere($col, 'like', "%{$search}%");
                }
            });
        }

        return Inertia::render('Masters/Index', [
            'master' => $master,
            'label' => $config['label'],
            'singular' => $config['singular'],
            'columns' => $config['columns'],
            'fields' => $config['fields'],
            'rows' => $query->latest('id')->paginate(15)->withQueryString(),
            'filters' => ['search' => $search],
        ]);
    }

    public function store(Request $request, string $master)
    {
        $config = MasterRegistry::get($master);
        $data = $request->validate(MasterRegistry::rules($master));
        $config['model']::create($data);

        return back()->with('success', $config['singular'].' created.');
    }

    /**
     * Quick-add a master from a dropdown (customers / references / vehicles),
     * returning the new record as JSON so the combobox can select it.
     */
    public function quickStore(Request $request, string $master)
    {
        abort_unless(in_array($master, ['customers', 'references', 'vehicles', 'expense-categories'], true), 404);
        $config = MasterRegistry::get($master);
        $field = $config['fields'][0]['name']; // name / number

        $data = $request->validate([$field => ['required', 'string', 'max:255']]);
        $record = $config['model']::firstOrCreate([$field => trim($data[$field])]);

        return response()->json(['id' => $record->id, 'label' => $record->{$field}]);
    }

    /**
     * Bulk-add: create many records from a comma-separated list. Only the
     * primary field is set; other required fields fall back to sensible defaults.
     */
    public function bulkStore(Request $request, string $master)
    {
        $config = MasterRegistry::get($master);
        $request->validate(['values' => ['required', 'string']]);
        $field = $config['fields'][0]['name'];

        $defaults = [];
        foreach (array_slice($config['fields'], 1) as $f) {
            if (($f['required'] ?? false) || array_key_exists('default', $f)) {
                $defaults[$f['name']] = $f['default']
                    ?? ($f['type'] === 'select' ? ($f['options'][0] ?? null) : ($f['type'] === 'number' ? 0 : ''));
            }
        }

        $created = 0;
        collect(explode(',', $request->string('values')))
            ->map(fn ($v) => trim($v))
            ->filter()
            ->unique()
            ->each(function ($value) use ($config, $field, $defaults, &$created) {
                $record = $config['model']::firstOrCreate([$field => $value], $defaults);
                if ($record->wasRecentlyCreated) {
                    $created++;
                }
            });

        return back()->with('success', "{$created} {$config['label']} added.");
    }

    public function update(Request $request, string $master, int $id)
    {
        $config = MasterRegistry::get($master);
        $data = $request->validate(MasterRegistry::rules($master));
        $config['model']::findOrFail($id)->update($data);

        return back()->with('success', $config['singular'].' updated.');
    }

    public function destroy(string $master, int $id)
    {
        $config = MasterRegistry::get($master);
        $config['model']::findOrFail($id)->delete();

        return back()->with('success', $config['singular'].' deleted.');
    }

    /** Delete many masters at once; records still in use are skipped. */
    public function bulkDestroy(Request $request, string $master)
    {
        $config = MasterRegistry::get($master);
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer']]);

        $deleted = 0;
        $blocked = 0;
        foreach ($config['model']::whereIn('id', $data['ids'])->get() as $record) {
            try {
                $record->delete();
                $deleted++;
            } catch (\Illuminate\Database\QueryException $e) {
                $blocked++; // referenced by a transaction/entry
            }
        }

        $msg = "{$deleted} deleted.";
        if ($blocked > 0) {
            $msg .= " {$blocked} in use and skipped.";
        }

        return back()->with('success', $msg);
    }
}
