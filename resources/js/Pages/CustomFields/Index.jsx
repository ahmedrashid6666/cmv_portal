import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card } from '@/Components/ui/Card';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

const input = 'w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';

export default function CustomFieldsIndex({ fields }) {
    const [editing, setEditing] = useState(null);
    const blank = { label: '', type: 'text', options_text: '', required: false, active: true, sort_order: 0 };
    const { data, setData, post, put, processing, errors, reset } = useForm(blank);

    const openNew = () => { setEditing(null); reset(); setData(blank); };
    const openEdit = (f) => {
        setEditing(f.id);
        setData({ label: f.label, type: f.type, options_text: (f.options || []).join(', '), required: f.required, active: f.active, sort_order: f.sort_order });
    };
    const submit = (e) => {
        e.preventDefault();
        const opts = { onSuccess: openNew };
        editing ? put(route('custom-fields.update', editing), opts) : post(route('custom-fields.store'), opts);
    };
    const del = (f) => { if (confirm(`Remove field "${f.label}"? Existing saved values stay in the data.`)) router.delete(route('custom-fields.destroy', f.id)); };

    return (
        <AuthenticatedLayout header="Custom Fields">
            <Head title="Custom Fields" />

            <p className="mb-4 text-sm text-slate-500">
                Add your own fields to the Transaction Entry form — no coding needed. They appear in an
                "Additional Details" section on every transaction.
            </p>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <Card title={`Fields (${fields.length})`} className="lg:col-span-2">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left text-xs uppercase text-slate-500">
                                    <th className="py-2 pr-4">Order</th>
                                    <th className="py-2 pr-4">Label</th>
                                    <th className="py-2 pr-4">Type</th>
                                    <th className="py-2 pr-4">Required</th>
                                    <th className="py-2 pr-4">Status</th>
                                    <th className="py-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {fields.length === 0 && <tr><td colSpan="6" className="py-8 text-center text-slate-400">No custom fields yet.</td></tr>}
                                {fields.map((f) => (
                                    <tr key={f.id} className="border-b last:border-0 hover:bg-slate-50">
                                        <td className="py-2 pr-4 text-slate-400">{f.sort_order}</td>
                                        <td className="py-2 pr-4 font-medium text-navy-800">{f.label}</td>
                                        <td className="py-2 pr-4"><span className="rounded bg-slate-100 px-2 py-0.5 text-xs">{f.type}</span></td>
                                        <td className="py-2 pr-4">{f.required ? 'Yes' : '—'}</td>
                                        <td className="py-2 pr-4">{f.active ? <span className="text-emerald-600">Active</span> : <span className="text-slate-400">Hidden</span>}</td>
                                        <td className="py-2 whitespace-nowrap text-right">
                                            <button onClick={() => openEdit(f)} className="text-primary-600 hover:underline">Edit</button>
                                            <button onClick={() => del(f)} className="ml-3 text-accent-red hover:underline">Remove</button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>

                <Card title={editing ? 'Edit Field' : 'Add Field'} action={editing && <button onClick={openNew} className="text-xs text-slate-500 hover:underline">Cancel</button>}>
                    <form onSubmit={submit} className="space-y-3">
                        <Field label="Label" error={errors.label}>
                            <input className={input} value={data.label} onChange={(e) => setData('label', e.target.value)} placeholder="e.g. Container No" />
                        </Field>
                        <Field label="Type" error={errors.type}>
                            <select className={input} value={data.type} onChange={(e) => setData('type', e.target.value)}>
                                <option value="text">Text</option>
                                <option value="number">Number</option>
                                <option value="date">Date</option>
                                <option value="select">Dropdown</option>
                            </select>
                        </Field>
                        {data.type === 'select' && (
                            <Field label="Options (comma-separated)" error={errors.options_text}>
                                <input className={input} value={data.options_text} onChange={(e) => setData('options_text', e.target.value)} placeholder="Sea, Air, Land" />
                            </Field>
                        )}
                        <Field label="Display Order" error={errors.sort_order}>
                            <input type="number" className={input} value={data.sort_order} onChange={(e) => setData('sort_order', e.target.value)} />
                        </Field>
                        <label className="flex items-center gap-2 text-sm text-slate-600">
                            <input type="checkbox" className="rounded border-slate-300 text-primary-600 focus:ring-primary-500" checked={data.required} onChange={(e) => setData('required', e.target.checked)} />
                            Required
                        </label>
                        <label className="flex items-center gap-2 text-sm text-slate-600">
                            <input type="checkbox" className="rounded border-slate-300 text-primary-600 focus:ring-primary-500" checked={data.active} onChange={(e) => setData('active', e.target.checked)} />
                            Active (show on form)
                        </label>
                        <button disabled={processing} className="w-full rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-primary-700 disabled:opacity-50">
                            {editing ? 'Update Field' : 'Add Field'}
                        </button>
                    </form>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}

function Field({ label, error, children }) {
    return (
        <label className="block">
            <span className="mb-1 block text-xs font-medium text-slate-600">{label}</span>
            {children}
            {error && <span className="mt-1 block text-xs text-accent-red">{error}</span>}
        </label>
    );
}
