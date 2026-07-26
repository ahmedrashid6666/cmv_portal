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
