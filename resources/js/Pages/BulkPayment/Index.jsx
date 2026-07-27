import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card } from '@/Components/ui/Card';
import { AED, fmtDate } from '@/lib/format';
import { Head, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const input = 'rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';

export default function BulkPayment({ meta, filters, entries, paymentMethods }) {
    const isBorrowed = meta.type === 'borrowed';
    const [search, setSearch] = useState(filters.search || '');
    const [selected, setSelected] = useState({});          // id -> true
    const [mode, setMode] = useState('fifo');
    const [manual, setManual] = useState({});              // id -> amount

    const { data, setData, post, processing, errors } = useForm({
        mode: 'fifo',
        amount: '',
        payment_date: new Date().toISOString().slice(0, 10),
        payment_method_id: '',
        note: '',
        entry_ids: [],
        allocations: {},
    });

    const doSearch = (e) => { e?.preventDefault(); router.get(route('bulk.index', meta.slug), { search }, { preserveState: true, replace: true }); };
    const toggle = (id) => setSelected((s) => ({ ...s, [id]: !s[id] }));
    const selectedEntries = entries.filter((e) => selected[e.id]);
    const selectedBalance = selectedEntries.reduce((s, e) => s + e.balance_amount, 0);

    // Live FIFO preview
    const fifoPreview = useMemo(() => {
        if (mode !== 'fifo') return {};
        let remaining = parseFloat(data.amount) || 0;
        const p = {};
        for (const e of selectedEntries) {
            if (remaining <= 0) break;
            const take = Math.min(remaining, e.balance_amount);
            p[e.id] = Math.round(take * 100) / 100;
            remaining -= take;
        }
        return p;
    }, [mode, data.amount, selected, entries]);

    const manualTotal = Object.values(manual).reduce((s, v) => s + (parseFloat(v) || 0), 0);

    const submit = (e) => {
        e.preventDefault();
        const payload = {
            ...data,
            mode,
            entry_ids: selectedEntries.map((x) => x.id),
            amount: mode === 'fifo' ? data.amount : null,
            allocations: mode === 'manual' ? manual : {},
        };
        router.post(route('bulk.store', meta.slug), payload, { onSuccess: () => { setSelected({}); setManual({}); } });
    };

    return (
        <AuthenticatedLayout header={`${meta.actionLabel} — ${meta.moduleLabel}`}>
            <Head title={meta.actionLabel} />

            {/* Search */}
            <Card className="mb-4">
                <form onSubmit={doSearch} className="flex flex-wrap items-end gap-3">
                    <label className="block flex-1">
                        <span className="mb-1 block text-xs font-medium text-slate-600">Search by Reference or {isBorrowed ? 'Person' : 'Customer'} Name</span>
                        <input className={input + ' w-full max-w-md'} value={search} onChange={(e) => setSearch(e.target.value)} placeholder="e.g. JRY or ESQUBE" />
                    </label>
                    <button className="rounded-lg bg-navy-700 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800">Search</button>
                </form>
            </Card>

            {filters.search && (
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    {/* Results */}
                    <Card title={`Open entries (${entries.length})`} className="lg:col-span-2">
                        {entries.length === 0 ? (
                            <p className="py-8 text-center text-slate-400">No pending or partially {isBorrowed ? 'returned' : 'paid'} entries found for “{filters.search}”.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b text-left text-xs uppercase text-slate-500">
                                            <th className="py-2 pr-2"></th>
                                            <th className="py-2 pr-3">Date</th>
                                            <th className="py-2 pr-3">Name / Ref</th>
                                            <th className="py-2 pr-3 text-right">Total</th>
                                            <th className="py-2 pr-3 text-right">Balance</th>
                                            {mode === 'manual' && <th className="py-2 pr-3 text-right">Allocate</th>}
                                            {mode === 'fifo' && <th className="py-2 pr-3 text-right">Will apply</th>}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {entries.map((e) => (
                                            <tr key={e.id} className={'border-b last:border-0 ' + (selected[e.id] ? 'bg-primary-50' : '')}>
                                                <td className="py-2 pr-2">
                                                    <input type="checkbox" className="rounded border-slate-300 text-primary-600 focus:ring-primary-500" checked={!!selected[e.id]} onChange={() => toggle(e.id)} />
                                                </td>
                                                <td className="py-2 pr-3 whitespace-nowrap">{fmtDate(e.entry_date)}</td>
                                                <td className="py-2 pr-3">
                                                    <div className="font-medium text-navy-800">{e.party_name}</div>
                                                    {e.reference && <div className="text-xs text-slate-400">{e.reference}</div>}
                                                </td>
                                                <td className="py-2 pr-3 text-right">{AED(e.total_amount)}</td>
                                                <td className="py-2 pr-3 text-right font-semibold text-accent-red">{AED(e.balance_amount)}</td>
                                                {mode === 'manual' && (
                                                    <td className="py-2 pr-3 text-right">
                                                        {selected[e.id] ? (
                                                            <input type="number" step="0.01" max={e.balance_amount} className={input + ' w-24 text-right'} value={manual[e.id] ?? ''} onChange={(ev) => setManual({ ...manual, [e.id]: ev.target.value })} />
                                                        ) : '—'}
                                                    </td>
                                                )}
                                                {mode === 'fifo' && (
                                                    <td className="py-2 pr-3 text-right font-semibold text-emerald-700">{selected[e.id] && fifoPreview[e.id] ? AED(fifoPreview[e.id]) : '—'}</td>
                                                )}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Card>

                    {/* Apply payment */}
                    <Card title={meta.actionLabel}>
                        <form onSubmit={submit} className="space-y-3">
                            <div className="rounded-lg bg-slate-50 p-3 text-sm">
                                <div className="flex justify-between"><span className="text-slate-500">Selected</span><span className="font-semibold">{selectedEntries.length} entr(y/ies)</span></div>
                                <div className="flex justify-between"><span className="text-slate-500">Their balance</span><span className="font-semibold text-accent-red">{AED(selectedBalance)}</span></div>
                            </div>

                            <div>
                                <span className="mb-1 block text-xs font-medium text-slate-600">Allocation</span>
                                <div className="flex gap-2">
                                    <button type="button" onClick={() => setMode('fifo')} className={'flex-1 rounded-lg border px-3 py-2 text-sm font-semibold ' + (mode === 'fifo' ? 'border-primary-500 bg-primary-50 text-primary-700' : 'border-slate-300 text-slate-600')}>Auto (Oldest first)</button>
                                    <button type="button" onClick={() => setMode('manual')} className={'flex-1 rounded-lg border px-3 py-2 text-sm font-semibold ' + (mode === 'manual' ? 'border-primary-500 bg-primary-50 text-primary-700' : 'border-slate-300 text-slate-600')}>Manual</button>
                                </div>
                            </div>

                            {mode === 'fifo' ? (
                                <label className="block">
                                    <span className="mb-1 block text-xs font-medium text-slate-600">Total {isBorrowed ? 'Return' : 'Payment'} Amount</span>
                                    <input type="number" step="0.01" className={input + ' w-full'} value={data.amount} onChange={(e) => setData('amount', e.target.value)} />
                                    {errors.amount && <span className="mt-1 block text-xs text-accent-red">{errors.amount}</span>}
                                </label>
                            ) : (
                                <p className="rounded-lg bg-amber-50 p-2 text-xs text-amber-700">Enter an amount per selected row in the table. Total: <b>{AED(manualTotal)}</b></p>
                            )}

                            <label className="block">
                                <span className="mb-1 block text-xs font-medium text-slate-600">{isBorrowed ? 'Return' : 'Payment'} Date</span>
                                <input type="date" className={input + ' w-full'} value={data.payment_date} onChange={(e) => setData('payment_date', e.target.value)} />
                            </label>
                            <label className="block">
                                <span className="mb-1 block text-xs font-medium text-slate-600">Received / Paid Via</span>
                                <select className={input + ' w-full'} value={data.payment_method_id} onChange={(e) => setData('payment_method_id', e.target.value)}>
                                    <option value="">—</option>
                                    {paymentMethods.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                                </select>
                            </label>
                            <label className="block">
                                <span className="mb-1 block text-xs font-medium text-slate-600">Note</span>
                                <input className={input + ' w-full'} value={data.note} onChange={(e) => setData('note', e.target.value)} />
                            </label>

                            {errors.bulk && <p className="rounded-lg bg-red-50 p-2 text-xs text-accent-red-dark">{errors.bulk}</p>}

                            <button disabled={processing || selectedEntries.length === 0} className="w-full rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-primary-700 disabled:opacity-50">
                                Apply {meta.actionLabel}
                            </button>
                        </form>
                    </Card>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
