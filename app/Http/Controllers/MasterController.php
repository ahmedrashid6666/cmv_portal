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
            $first = array_key_first($config['columns']);
            $query->where($first, 'like', "%{$search}%");
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
        abort_unless(in_array($master, ['customers', 'references', 'vehicles'], true), 404);
        $config = MasterRegistry::get($master);
        $field = $config['fields'][0]['name']; // name / number

        $data = $request->validate([$field => ['required', 'string', 'max:255']]);
        $record = $config['model']::firstOrCreate([$field => trim($data[$field])]);

        return response()->json(['id' => $record->id, 'label' => $record->{$field}]);
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
}
