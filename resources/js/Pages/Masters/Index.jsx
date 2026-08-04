import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card } from '@/Components/ui/Card';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const input = 'w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';

export default function MastersIndex({ master, label, singular, columns, fields, rows, filters }) {
    const role = usePage().props.auth.user.role;
    const canWrite = ['super_admin', 'admin'].includes(role);
    const [editing, setEditing] = useState(null);

    const blank = Object.fromEntries(fields.map((f) => [f.name, f.default ?? '']));
    const { data, setData, post, put, processing, errors, reset } = useForm(blank);

    // Search
    const [search, setSearch] = useState(filters?.search ?? '');
    const runSearch = (e) => { e?.preventDefault(); router.get(route('masters.index', master), { search }, { preserveState: true, replace: true }); };
    const clearSearch = () => { setSearch(''); router.get(route('masters.index', master), {}, { preserveState: true, replace: true }); };

    const openNew = () => { setEditing(null); reset(); setData(blank); };
    const openEdit = (row) => { setEditing(row.id); setData(Object.fromEntries(fields.map((f) => [f.name, row[f.name] ?? '']))); };

    const submit = (e) => {
        e.preventDefault();
        const opts = { onSuccess: () => { reset(); setEditing(null); setData(blank); } };
        editing ? put(route('masters.update', [master, editing]), opts) : post(route('masters.store', master), opts);
    };
    const del = (id) => { if (confirm(`Delete this ${singular}?`)) router.delete(route('masters.destroy', [master, id])); };

    // Bulk add: comma-separated values
    const bulk = useForm({ values: '' });
    const submitBulk = (e) => { e.preventDefault(); bulk.post(route('masters.bulk', master), { onSuccess: () => bulk.reset() }); };
    const primaryLabel = fields[0]?.label ?? 'value';

    // Bulk delete
    const [selected, setSelected] = useState([]);
    const ids = rows.data.map((r) => r.id);
    const allChecked = ids.length > 0 && ids.every((id) => selected.includes(id));
    const toggleAll = () => setSelected(allChecked ? [] : ids);
    const toggleOne = (id) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));
    const bulkDelete = () => {
        if (!confirm(`Delete ${selected.length} selected ${label}? Records still in use are skipped.`)) return;
        router.post(route('masters.bulk-destroy', master), { ids: selected }, { preserveScroll: true, onSuccess: () => setSelected([]) });
    };

    return (
        <AuthenticatedLayout header={label}>
            <Head title={label} />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <Card title={label} className="lg:col-span-2">
                    <form onSubmit={runSearch} className="mb-3 flex items-center gap-2">
                        <input
                            className={input + ' max-w-xs'}
                            placeholder={`Search ${label.toLowerCase()}…`}
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                        <button className="rounded-lg bg-navy-700 px-3 py-2 text-sm font-semibold text-white hover:bg-navy-800">Search</button>
                        {filters?.search && (
                            <button type="button" onClick={clearSearch} className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50">Clear</button>
                        )}
                        {filters?.search && <span className="text-xs text-slate-400">Showing results for “{filters.search}”</span>}
                    </form>
                    {canWrite && selected.length > 0 && (
                        <div className="mb-3 flex items-center gap-3 rounded-lg bg-navy-800 px-4 py-2 text-sm text-white">
                            <span>{selected.length} selected</span>
                            <button onClick={bulkDelete} className="rounded-lg bg-accent-red px-3 py-1.5 font-semibold hover:bg-accent-red-dark">Delete selected</button>
                            <button onClick={() => setSelected([])} className="text-navy-200 hover:text-white">Clear</button>
                        </div>
                    )}
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left text-xs uppercase text-slate-500">
                                    {canWrite && <th className="py-2 pr-3"><input type="checkbox" className="rounded border-slate-300 text-primary-600 focus:ring-primary-500" checked={allChecked} onChange={toggleAll} /></th>}
                                    {Object.values(columns).map((c) => <th key={c} className="py-2 pr-4">{c}</th>)}
                                    {canWrite && <th className="py-2"></th>}
                                </tr>
                            </thead>
                            <tbody>
                                {rows.data.length === 0 && (
                                    <tr><td colSpan="9" className="py-8 text-center text-slate-400">{filters?.search ? 'No records match your search.' : 'No records yet.'}</td></tr>
                                )}
                                {rows.data.map((row) => (
                                    <tr key={row.id} className={'border-b last:border-0 hover:bg-slate-200 ' + (selected.includes(row.id) ? 'bg-primary-50' : '')}>
                                        {canWrite && <td className="py-2 pr-3"><input type="checkbox" className="rounded border-slate-300 text-primary-600 focus:ring-primary-500" checked={selected.includes(row.id)} onChange={() => toggleOne(row.id)} /></td>}
                                        {Object.keys(columns).map((k) => <td key={k} className="py-2 pr-4">{String(row[k] ?? '—')}</td>)}
                                        {canWrite && (
                                            <td className="py-2 whitespace-nowrap text-right">
                                                <button onClick={() => openEdit(row)} className="text-primary-600 hover:underline">Edit</button>
                                                <button onClick={() => del(row.id)} className="ml-3 text-accent-red hover:underline">Delete</button>
                                            </td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {rows.last_page > 1 && (
                        <div className="mt-4 flex flex-wrap gap-1">
                            {rows.links.map((l, i) => (
                                <button key={i} disabled={!l.url} onClick={() => l.url && router.get(l.url)}
                                    className={'rounded px-3 py-1 text-sm ' + (l.active ? 'bg-primary-600 text-white' : l.url ? 'text-slate-600 hover:bg-slate-100' : 'text-slate-300')}
                                    dangerouslySetInnerHTML={{ __html: l.label }} />
                            ))}
                        </div>
                    )}
                </Card>

                {canWrite && (
                    <Card title={editing ? `Edit ${singular}` : `Add ${singular}`} action={editing && <button onClick={openNew} className="text-xs text-slate-500 hover:underline">Cancel</button>}>
                        <form onSubmit={submit} className="space-y-3">
                            {fields.map((field) => (
                                <label key={field.name} className="block">
                                    <span className="mb-1 block text-xs font-medium text-slate-600">
                                        {field.label} {field.required && <span className="text-accent-red">*</span>}
                                    </span>
                                    {field.type === 'select' ? (
                                        <select className={input} value={data[field.name]} onChange={(e) => setData(field.name, e.target.value)}>
                                            <option value="">Select…</option>
                                            {field.options.map((o) => <option key={o} value={o}>{o}</option>)}
                                        </select>
                                    ) : field.type === 'textarea' ? (
                                        <textarea rows="2" className={input} value={data[field.name]} onChange={(e) => setData(field.name, e.target.value)} />
                                    ) : (
                                        <input type={field.type === 'number' ? 'number' : 'text'} step="0.01" className={input} value={data[field.name]} onChange={(e) => setData(field.name, e.target.value)} />
                                    )}
                                    {errors[field.name] && <span className="mt-1 block text-xs text-accent-red">{errors[field.name]}</span>}
                                </label>
                            ))}
                            <button disabled={processing} className="w-full rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-primary-700 disabled:opacity-50">
                                {editing ? 'Update' : 'Add'}
                            </button>
                        </form>

                        {/* Bulk add (comma-separated) */}
                        {!editing && (
                            <div className="mt-5 border-t pt-4">
                                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Bulk add</p>
                                <form onSubmit={submitBulk} className="space-y-2">
                                    <textarea
                                        rows="3"
                                        className={input}
                                        placeholder={`Paste multiple ${label.toLowerCase()} separated by commas — e.g. ${primaryLabel} 1, ${primaryLabel} 2, ${primaryLabel} 3`}
                                        value={bulk.data.values}
                                        onChange={(e) => bulk.setData('values', e.target.value)}
                                    />
                                    {bulk.errors.values && <span className="block text-xs text-accent-red">{bulk.errors.values}</span>}
                                    <button disabled={bulk.processing} className="w-full rounded-lg border border-primary-600 px-4 py-2 text-sm font-semibold text-primary-700 hover:bg-primary-50 disabled:opacity-50">
                                        Add All (comma-separated)
                                    </button>
                                    <p className="text-[11px] text-slate-400">Duplicates are skipped. Only the {primaryLabel} is set; edit afterwards to add other details.</p>
                                </form>
                            </div>
                        )}
                    </Card>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
