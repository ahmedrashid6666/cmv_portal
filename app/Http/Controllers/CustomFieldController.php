<?php

namespace App\Http\Controllers;

use App\Models\CustomField;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CustomFieldController extends Controller
{
    public function index()
    {
        return Inertia::render('CustomFields/Index', [
            'fields' => CustomField::orderBy('sort_order')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['key'] = $this->uniqueKey($data['label']);
        CustomField::create($data);

        return back()->with('success', 'Field added.');
    }

    public function update(Request $request, CustomField $customField)
    {
        $data = $this->validated($request);
        // key stays stable so existing data keeps mapping
        unset($data['label_key']);
        $customField->update($data);

        return back()->with('success', 'Field updated.');
    }

    public function destroy(CustomField $customField)
    {
        $customField->delete();

        return back()->with('success', 'Field removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['text', 'number', 'date', 'select'])],
            'options_text' => ['nullable', 'string'],
            'required' => ['boolean'],
            'active' => ['boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $data['options'] = $data['type'] === 'select' && ! empty($data['options_text'])
            ? array_values(array_filter(array_map('trim', explode(',', $data['options_text']))))
            : null;
        unset($data['options_text']);
        $data['sort_order'] = $data['sort_order'] ?? 0;

        return $data;
    }

    private function uniqueKey(string $label): string
    {
        $base = Str::slug($label, '_') ?: 'field';
        $key = $base;
        $i = 1;
        while (CustomField::where('key', $key)->exists()) {
            $key = $base.'_'.$i++;
        }

        return $key;
    }
}
