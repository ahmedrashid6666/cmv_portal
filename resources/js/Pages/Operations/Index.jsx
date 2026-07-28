import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card } from '@/Components/ui/Card';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

const input = 'rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';

const statusStyle = {
    // ledger
    pending: 'bg-red-100 text-accent-red-dark',
    partial: 'bg-amber-100 text-amber-700',
    returned: 'bg-emerald-100 text-emerald-700',
    // transaction invoice status
    paid: 'bg-emerald-100 text-emerald-700',
    unpaid: 'bg-red-100 text-accent-red-dark',
};

export default function Operations({ tabs, type, columns, rows, filters, isLedger, statusOptions }) {
    const role = usePage().props.auth.user.role;
    const canWrite = ['super_admin', 'admin', 'accountant'].includes(role);
    const canBulkDelete = ['super_admin', 'admin'].includes(role);

    const [f, setF] = useState(filters);
    const [selected, setSelected] = useState([]);

    const go = (params) => router.get(route('operations.index'), { type, ...params }, { preserveState: true, replace: true, onSuccess: () => setSelected([]) });
    const switchTab = (key) => { setSelected([]); router.get(route('operations.index'), { type: key }, { preserveState: true }); };
    const applyFilters = (e) => { e?.preventDefault(); go(f); };
    const reset = () => { setF({ from: '', to: '', search: '', status: '' }); router.get(route('operations.index'), { type, from: '', to: '' }); };

    const ids = rows.data.map((r) => r.id);
    const allChecked = ids.length > 0 && ids.every((id) => selected.includes(id));
    const toggleAll = () => setSelected(allChecked ? [] : ids);
    const toggle = (id) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));

    const bulkDelete = () => {
        if (!selected.length) return;
        if (!confirm(`Delete ${selected.length} selected record(s)? They go to the Recycle Bin.`)) return;
        router.post(route('operations.bulk-delete'), { type, ids: selected }, { preserveScroll: true, onSuccess: () => setSelected([]) });
    };

    return (
        <AuthenticatedLayout header="Operations">
            <Head title="Operations" />

            {/* Type tabs + quick actions */}
            <div className="mb-4 flex flex-wrap items-center gap-2">
                <div className="flex rounded-lg border border-slate-200 bg-white p-1 shadow-sm">
                    {tabs.map((t) => (
                        <button
                            key={t.key}
                            onClick={() => switchTab(t.key)}
                            className={'rounded-md px-4 py-1.5 text-sm font-semibold transition ' + (type === t.key ? 'bg-primary-600 text-white shadow' : 'text-slate-600 hover:bg-slate-100')}
                        >
                            {t.label}
                        </button>
                    ))}
                </div>
                <div className="ml-auto flex gap-2">
                    {canWrite && <Link href={route('entry.create')} className="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-primary-700">+ Add Entry</Link>}
                    {isLedger && canWrite && (
                        <Link href={route('bulk.index', type)} className="rounded-lg border border-navy-600 px-4 py-2 text-sm font-semibold text-navy-700 hover:bg-navy-50">
                            {type === 'borrowed' ? 'Bulk Return' : 'Bulk Payment'}
                        </Link>
                    )}
                </div>
            </div>

            {/* Filters */}
            <Card className="mb-4">
                <form onSubmit={applyFilters} className="flex flex-wrap items-end gap-2">
                    <label className="block">
                        <span className="mb-1 block text-[11px] font-medium text-slate-500">Search</span>
                        <input className={input + ' w-48'} placeholder="name / invoice / ref" value={f.search || ''} onChange={(e) => setF({ ...f, search: e.target.value })} />
                    </label>
                    <label className="block">
                        <span className="mb-1 block text-[11px] font-medium text-slate-500">From</span>
                        <input type="date" className={input} value={f.from || ''} onChange={(e) => setF({ ...f, from: e.target.value })} />
                    </label>
                    <label className="block">
                        <span className="mb-1 block text-[11px] font-medium text-slate-500">To</span>
                        <input type="date" className={input} value={f.to || ''} onChange={(e) => setF({ ...f, to: e.target.value })} />
                    </label>
                    {isLedger && (
                        <label className="block">
                            <span className="mb-1 block text-[11px] font-medium text-slate-500">Status</span>
                            <select className={input} value={f.status || ''} onChange={(e) => setF({ ...f, status: e.target.value })}>
                                <option value="">All</option>
                                {Object.entries(statusOptions).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                            </select>
                        </label>
                    )}
                    <button className="rounded-lg bg-navy-700 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800">Filter</button>
                    <button type="button" onClick={reset} className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50">Reset</button>
                    <span className="ml-2 text-xs text-slate-400">
                        {f.from || f.to ? `Showing ${f.from || '…'} → ${f.to || '…'}` : 'Showing all dates'}
                    </span>
                </form>
            </Card>

            {/* Bulk action bar */}
            {canBulkDelete && selected.length > 0 && (
                <div className="mb-3 flex items-center gap-3 rounded-lg bg-navy-800 px-4 py-2 text-sm text-white">
                    <span>{selected.length} selected</span>
                    <button onClick={bulkDelete} className="rounded-lg bg-accent-red px-3 py-1.5 font-semibold hover:bg-accent-red-dark">Delete selected</button>
                    <button onClick={() => setSelected([])} className="text-navy-200 hover:text-white">Clear</button>
                </div>
            )}

            {/* Table */}
            <Card>
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left text-xs uppercase text-slate-500">
                                {canBulkDelete && (
                                    <th className="py-2 pr-3">
                                        <input type="checkbox" className="rounded border-slate-300 text-primary-600 focus:ring-primary-500" checked={allChecked} onChange={toggleAll} />
                                    </th>
                                )}
                                {columns.map((c, i) => <th key={c} className={'py-2 pr-3 ' + (i >= 4 ? 'text-right' : '')}>{c}</th>)}
                                <th className="py-2 pr-3">Status</th>
                                {canWrite && <th className="py-2"></th>}
                            </tr>
                        </thead>
                        <tbody>
                            {rows.data.length === 0 && (
                                <tr><td colSpan={columns.length + 3} className="py-10 text-center text-slate-400">No records for this filter.</td></tr>
                            )}
                            {rows.data.map((r) => (
                                <tr key={r.id} className={'border-b last:border-0 hover:bg-slate-50 ' + (selected.includes(r.id) ? 'bg-primary-50' : '')}>
                                    {canBulkDelete && (
                                        <td className="py-2 pr-3">
                                            <input type="checkbox" className="rounded border-slate-300 text-primary-600 focus:ring-primary-500" checked={selected.includes(r.id)} onChange={() => toggle(r.id)} />
                                        </td>
                                    )}
                                    {r.cells.map((cell, i) => (
                                        <td key={i} className={'py-2 pr-3 ' + (i >= 4 ? 'text-right tabular-nums' : '') + (i === 0 ? ' whitespace-nowrap' : '')}>{cell}</td>
                                    ))}
                                    <td className="py-2 pr-3">
                                        <span className={'rounded-full px-2 py-0.5 text-xs font-semibold ' + (statusStyle[r.status] || 'bg-slate-100 text-slate-600')}>{r.status}</span>
                                    </td>
                                    {canWrite && (
                                        <td className="py-2 whitespace-nowrap text-right">
                                            <Link href={r.edit_url} className="font-semibold text-primary-600 hover:underline">Edit</Link>
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
                            <Link key={i} href={l.url || '#'} className={'rounded px-3 py-1 text-sm ' + (l.active ? 'bg-primary-600 text-white' : l.url ? 'text-slate-600 hover:bg-slate-100' : 'text-slate-300')} dangerouslySetInnerHTML={{ __html: l.label }} />
                        ))}
                    </div>
                )}
            </Card>
        </AuthenticatedLayout>
    );
}
