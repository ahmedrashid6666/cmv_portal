import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card } from '@/Components/ui/Card';
import LedgerEntryForm from '@/Components/forms/LedgerEntryForm';
import { AED, fmtDate } from '@/lib/format';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

const input = 'w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';

const statusStyle = {
    pending: 'bg-red-100 text-accent-red-dark',
    partial: 'bg-amber-100 text-amber-700',
    returned: 'bg-emerald-100 text-emerald-700',
};
const statusLabels = (t) => ({
    pending: 'Pending',
    partial: t === 'borrowed' ? 'Partially Returned' : 'Partially Paid',
    returned: 'Returned',
});

export default function LedgerIndex({ meta, summary, entries, filters, customers = [], references = [], vehicles = [] }) {
    const role = usePage().props.auth.user.role;
    const canWrite = ['super_admin', 'admin', 'accountant'].includes(role);
    const labels = statusLabels(meta.type);

    const [f, setF] = useState(filters);
    const [editingEntry, setEditingEntry] = useState(null);

    const del = (e) => { if (confirm('Delete this entry?')) router.delete(route('ledger.destroy', [meta.slug, e.id]), { preserveScroll: true }); };
    const applyFilters = (ev) => { ev?.preventDefault(); router.get(route('ledger.index', meta.slug), f, { preserveState: true, replace: true }); };
    const preset = (kind) => {
        const today = new Date();
        const iso = (d) => d.toISOString().slice(0, 10);
        let from = '';
        const to = iso(today);
        if (kind === 'today') from = iso(today);
        if (kind === 'week') { const d = new Date(today); d.setDate(d.getDate() - 6); from = iso(d); }
        if (kind === 'month') from = iso(new Date(today.getFullYear(), today.getMonth(), 1));
        const nf = { ...f, from, to };
        setF(nf);
        router.get(route('ledger.index', meta.slug), nf, { preserveState: true, replace: true });
    };
    const exportUrl = (format) => window.open(route('ledger.export', meta.slug) + '?' + new URLSearchParams({ ...f, format }).toString(), '_blank');

    return (
        <AuthenticatedLayout header={meta.label}>
            <Head title={meta.label} />

            <div className="mb-6 grid grid-cols-2 gap-4 md:grid-cols-5">
                <Summary label={`Total ${meta.label}`} value={summary.total} accent="text-navy-900" />
                <Summary label="Pending" value={summary.pending} accent="text-accent-red" />
                <Summary label={labels.partial} value={summary.partial} accent="text-amber-600" />
                <Summary label="Returned" value={summary.returned} accent="text-emerald-700" />
                <Summary label="Outstanding Balance" value={summary.outstanding} accent="text-primary-700" strong />
            </div>

            <div className="grid grid-cols-1 gap-6 xl:grid-cols-3">
                <div className="space-y-4 xl:col-span-2">
                    <Card>
                        <form onSubmit={applyFilters} className="flex flex-wrap items-end gap-2">
                            <input className={input + ' max-w-[180px]'} placeholder="Search name / ref / vehicle" value={f.search || ''} onChange={(e) => setF({ ...f, search: e.target.value })} />
                            <select className={input + ' max-w-[150px]'} value={f.status || ''} onChange={(e) => setF({ ...f, status: e.target.value })}>
                                <option value="">All statuses</option>
                                <option value="pending">Pending</option>
                                <option value="partial">{labels.partial}</option>
                                <option value="returned">Returned</option>
                            </select>
                            <input type="date" className={input + ' max-w-[150px]'} value={f.from || ''} onChange={(e) => setF({ ...f, from: e.target.value })} />
                            <input type="date" className={input + ' max-w-[150px]'} value={f.to || ''} onChange={(e) => setF({ ...f, to: e.target.value })} />
                            <button className="rounded-lg bg-navy-700 px-3 py-2 text-sm font-semibold text-white hover:bg-navy-800">Filter</button>
                        </form>
                        <div className="mt-3 flex flex-wrap items-center gap-2 text-xs">
                            <span className="text-slate-400">Quick:</span>
                            <button onClick={() => preset('today')} className="rounded-full border border-slate-300 px-3 py-1 hover:bg-slate-50">Today</button>
                            <button onClick={() => preset('week')} className="rounded-full border border-slate-300 px-3 py-1 hover:bg-slate-50">This Week</button>
                            <button onClick={() => preset('month')} className="rounded-full border border-slate-300 px-3 py-1 hover:bg-slate-50">This Month</button>
                            <button onClick={() => { setF({}); router.get(route('ledger.index', meta.slug)); }} className="rounded-full border border-slate-300 px-3 py-1 hover:bg-slate-50">Reset</button>
                            <span className="ml-auto flex gap-2">
                                <button onClick={() => exportUrl('xlsx')} className="rounded-lg border border-emerald-600 px-3 py-1 font-semibold text-emerald-700 hover:bg-emerald-50">Excel</button>
                                <button onClick={() => exportUrl('pdf')} className="rounded-lg border border-accent-red px-3 py-1 font-semibold text-accent-red hover:bg-red-50">PDF</button>
                            </span>
                        </div>
                    </Card>

                    <Card>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left text-xs uppercase text-slate-500">
                                        <th className="py-2 pr-3">Date</th>
                                        <th className="py-2 pr-3">{meta.partyLabel}</th>
                                        <th className="py-2 pr-3">Vehicle</th>
                                        <th className="py-2 pr-3 text-right">Total</th>
                                        <th className="py-2 pr-3 text-right">{meta.paidLabel}</th>
                                        <th className="py-2 pr-3 text-right">Balance</th>
                                        <th className="py-2 pr-3">Status</th>
                                        {canWrite && <th className="py-2"></th>}
                                    </tr>
                                </thead>
                                <tbody>
                                    {entries.data.length === 0 && <tr><td colSpan="8" className="py-8 text-center text-slate-400">No entries yet.</td></tr>}
                                    {entries.data.map((e) => (
                                        <tr key={e.id} className="border-b last:border-0 hover:bg-slate-50">
                                            <td className="py-2 pr-3 whitespace-nowrap">{fmtDate(e.entry_date)}</td>
                                            <td className="py-2 pr-3">
                                                <div className="font-medium text-navy-800">{e.party_name}</div>
                                                {e.reference && <div className="text-xs text-slate-400">{e.reference}</div>}
                                            </td>
                                            <td className="py-2 pr-3">{e.vehicle_number || '—'}</td>
                                            <td className="py-2 pr-3 text-right">{AED(e.total_amount)}</td>
                                            <td className="py-2 pr-3 text-right text-emerald-700">{AED(e.paid_amount)}</td>
                                            <td className="py-2 pr-3 text-right font-semibold text-accent-red">{AED(e.balance_amount)}</td>
                                            <td className="py-2 pr-3">
                                                <span className={'rounded-full px-2 py-0.5 text-xs font-semibold ' + statusStyle[e.status]}>{labels[e.status]}</span>
                                                {e.status === 'returned' && e.return_date && <div className="mt-0.5 text-[10px] text-slate-400">on {fmtDate(e.return_date)}</div>}
                                            </td>
                                            {canWrite && (
                                                <td className="py-2 whitespace-nowrap text-right">
                                                    <button onClick={() => setEditingEntry(e)} className="text-primary-600 hover:underline">Edit</button>
                                                    <button onClick={() => del(e)} className="ml-2 text-accent-red hover:underline">Del</button>
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        {entries.last_page > 1 && (
                            <div className="mt-4 flex flex-wrap gap-1">
                                {entries.links.map((l, i) => (
                                    <Link key={i} href={l.url || '#'} className={'rounded px-3 py-1 text-sm ' + (l.active ? 'bg-primary-600 text-white' : l.url ? 'text-slate-600 hover:bg-slate-100' : 'text-slate-300')} dangerouslySetInnerHTML={{ __html: l.label }} />
                                ))}
                            </div>
                        )}
                    </Card>
                </div>

                {canWrite && (
                    <Card
                        title={editingEntry ? `Edit ${meta.label}` : `Add ${meta.label}`}
                        action={editingEntry && <button onClick={() => setEditingEntry(null)} className="text-xs text-slate-500 hover:underline">Cancel</button>}
                    >
                        <LedgerEntryForm
                            key={editingEntry?.id ?? 'new'}
                            meta={meta}
                            entry={editingEntry}
                            labels={labels}
                            customers={customers}
                            references={references}
                            vehicles={vehicles}
                            onDone={() => setEditingEntry(null)}
                        />
                    </Card>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

function Summary({ label, value, accent, strong }) {
    return (
        <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <p className="text-xs uppercase text-slate-500">{label}</p>
            <p className={'mt-1 font-bold ' + accent + (strong ? ' text-2xl' : ' text-xl')}>{AED(value)}</p>
        </div>
    );
}
