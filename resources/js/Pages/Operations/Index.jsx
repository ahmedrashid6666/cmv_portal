import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card } from '@/Components/ui/Card';
import { AED, money } from '@/lib/format';
import { toLocalISODate, todayLocalISO } from '@/lib/date';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState, useEffect, useRef } from 'react';

const input = 'rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';

const statusStyle = {
    pending: 'bg-red-100 text-accent-red-dark',
    partial: 'bg-amber-100 text-amber-700',
    returned: 'bg-emerald-100 text-emerald-700',
    paid: 'bg-emerald-100 text-emerald-700',
    unpaid: 'bg-red-100 text-accent-red-dark',
};

export default function Operations({ tabs, type, columns, rows, filters, sort, sortKeys = [], align = null, totals = null, isLedger, statusOptions, actionLabel, bulkDeletable, paymentMethods }) {
    const role = usePage().props.auth.user.role;
    const canWrite = ['super_admin', 'admin', 'accountant'].includes(role);
    const canBulkDelete = ['super_admin', 'admin'].includes(role);
    const showChecks = bulkDeletable && canWrite;
    const isBorrowed = type === 'borrowed';
    const isRight = (i) => (align ? !!align[i] : i >= 4);

    const [f, setF] = useState(filters);
    const [selected, setSelected] = useState([]);
    const [payOpen, setPayOpen] = useState(false);
    const [settleRow, setSettleRow] = useState(null); // row.settle {kind:'credit'|'ledger', ...}
    const debounceTimer = useRef(null);
    const skipNextAutoSearch = useRef(true); // don't re-fire on mount (e.g. a pagination/sort click just navigated here)

    const go = (params) => router.get(route('operations.index'), { type, ...f, sort: sort?.by, dir: sort?.dir, ...params }, { preserveState: true, replace: true, onSuccess: () => setSelected([]) });

    // Auto-search with debounce on filter changes (search/date/status typing only).
    useEffect(() => {
        if (skipNextAutoSearch.current) { skipNextAutoSearch.current = false; return; }
        if (debounceTimer.current) clearTimeout(debounceTimer.current);
        debounceTimer.current = setTimeout(() => {
            go();
        }, 300);
        return () => clearTimeout(debounceTimer.current);
    }, [f]);
    const sortBy = (key) => {
        if (!key) return;
        const dir = sort?.by === key && sort?.dir === 'asc' ? 'desc' : 'asc';
        go({ sort: key, dir });
    };
    const arrow = (key) => {
        if (!key) return null;
        if (sort?.by !== key) return <span className="text-slate-300">⇅</span>;
        return <span className="text-primary-600">{sort.dir === 'asc' ? '↑' : '↓'}</span>;
    };
    const switchTab = (key) => { setSelected([]); router.get(route('operations.index'), { type: key }, { preserveState: true }); };
    const exportUrl = (format) => route('operations.export', {
        format, type,
        ...(filters.search ? { search: filters.search } : {}),
        ...(filters.from ? { from: filters.from } : {}),
        ...(filters.to ? { to: filters.to } : {}),
        ...(filters.status ? { status: filters.status } : {}),
        ...(sort?.by ? { sort: sort.by, dir: sort.dir } : {}),
    });
    const applyFilters = (e) => { e?.preventDefault(); go(f); };
    const reset = () => { setF({ from: '', to: '', search: '', status: '' }); router.get(route('operations.index'), { type }); };
    const preset = (kind) => {
        const today = new Date();
        const iso = toLocalISODate;
        let from = '';
        const to = iso(today);
        if (kind === 'today') from = iso(today);
        else if (kind === 'week') { const d = new Date(today); d.setDate(d.getDate() - 6); from = iso(d); }
        else if (kind === 'month') from = iso(new Date(today.getFullYear(), today.getMonth(), 1));
        else { setF({ ...f, from: '', to: '' }); go({ from: '', to: '' }); return; }
        setF({ ...f, from, to });
        go({ from, to });
    };

    const ids = rows.data.map((r) => r.id);
    const allChecked = ids.length > 0 && ids.every((id) => selected.includes(id));
    const toggleAll = () => setSelected(allChecked ? [] : ids);
    const toggle = (id) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));

    const bulkDelete = () => {
        if (!confirm(`Delete ${selected.length} selected record(s)? They go to the Recycle Bin.`)) return;
        router.post(route('operations.bulk-delete'), { type, ids: selected }, { preserveScroll: true, onSuccess: () => setSelected([]) });
    };

    const pay = useForm({ mode: 'fifo', amount: '', payment_date: todayLocalISO(), payment_method_id: '', note: '', entry_ids: [] });
    const submitPay = (e) => {
        e.preventDefault();
        router.post(route('bulk.store', type), { ...pay.data, entry_ids: selected }, {
            preserveScroll: true,
            onSuccess: () => { setPayOpen(false); setSelected([]); pay.reset(); },
        });
    };

    // Settle dialog (click a status badge): collect a payment, or edit the paid amount.
    const rcv = useForm({ transaction_id: null, amount: '', payment_date: todayLocalISO(), payment_method_id: '', note: '' });
    const led = useForm({ paid_amount: '' });
    const openSettle = (s) => {
        setSettleRow(s);
        if (s.kind === 'credit') rcv.setData({ transaction_id: s.id, amount: s.outstanding > 0 ? s.outstanding : '', payment_date: todayLocalISO(), payment_method_id: '', note: '' });
        else led.setData({ paid_amount: s.paid });
    };
    const closeSettle = () => setSettleRow(null);
    const submitReceive = (e) => { e.preventDefault(); rcv.post(route('credits.store'), { preserveScroll: true, onSuccess: closeSettle }); };
    const reversePayment = (pid) => { if (confirm('Reverse this payment?')) router.delete(route('credits.payment.destroy', pid), { preserveScroll: true, onSuccess: closeSettle }); };
    const submitLedgerSettle = (e) => { e.preventDefault(); led.put(route('ledger.settle', [settleRow.slug, settleRow.id]), { preserveScroll: true, onSuccess: closeSettle }); };
    const ledBalance = Math.max(0, (settleRow?.total || 0) - (parseFloat(led.data.paid_amount) || 0));

    return (
        <AuthenticatedLayout header="Operations">
            <Head title="Operations" />

            {/* Tabs + quick actions */}
            <div className="mb-4 flex flex-wrap items-center gap-2">
                <div className="flex flex-wrap rounded-lg border border-slate-200 bg-white p-1 shadow-sm">
                    {tabs.map((t) => (
                        <button key={t.key} onClick={() => switchTab(t.key)}
                            className={'rounded-md px-4 py-1.5 text-sm font-semibold transition ' + (type === t.key ? 'bg-primary-600 text-white shadow' : 'text-slate-600 hover:bg-slate-100')}>
                            {t.label}
                        </button>
                    ))}
                </div>
                <div className="ml-auto flex items-center gap-2">
                    <a href={exportUrl('xlsx')} className="rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-100">⬇ Excel</a>
                    <a href={exportUrl('pdf')} className="rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm font-semibold text-accent-red-dark hover:bg-red-100">⬇ PDF</a>
                    {canWrite && <Link href={route('entry.create')} className="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-primary-700">+ Add Entry</Link>}
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
                    <button type="button" onClick={reset} className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50">Reset</button>
                </form>
                <div className="mt-2 flex flex-wrap items-center gap-2 text-xs">
                    <span className="text-slate-400">Quick:</span>
                    {['today', 'week', 'month', 'all'].map((k) => (
                        <button key={k} onClick={() => preset(k)} className="rounded-full border border-slate-300 px-3 py-1 capitalize hover:bg-slate-50">
                            {k === 'week' ? 'This Week' : k === 'month' ? 'This Month' : k === 'all' ? 'All Dates' : 'Today'}
                        </button>
                    ))}
                    <span className="ml-1 text-slate-400">{f.from || f.to ? `Showing ${f.from || '…'} → ${f.to || '…'}` : 'Showing all dates'}</span>
                </div>
            </Card>

            {/* Bulk action bar */}
            {showChecks && selected.length > 0 && (
                <div className="mb-3 flex flex-wrap items-center gap-3 rounded-lg bg-navy-800 px-4 py-2 text-sm text-white">
                    <span>{selected.length} selected</span>
                    {isLedger && canWrite && (
                        <button onClick={() => setPayOpen(true)} className="rounded-lg bg-primary-500 px-3 py-1.5 font-semibold hover:bg-primary-400">
                            {isBorrowed ? 'Bulk Return' : 'Bulk Payment'}
                        </button>
                    )}
                    {canBulkDelete && <button onClick={bulkDelete} className="rounded-lg bg-accent-red px-3 py-1.5 font-semibold hover:bg-accent-red-dark">Delete selected</button>}
                    <button onClick={() => setSelected([])} className="text-navy-200 hover:text-white">Clear</button>
                </div>
            )}

            {/* Table */}
            <Card>
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left text-xs uppercase text-slate-500 align-bottom">
                                {showChecks && <th className="py-2 pr-3"><input type="checkbox" className="rounded border-slate-300 text-primary-600 focus:ring-primary-500" checked={allChecked} onChange={toggleAll} /></th>}
                                <th className="py-2 pr-3 leading-tight">Sl No</th>
                                {columns.map((c, i) => (
                                    <th key={c} className={'py-2 pr-3 leading-tight ' + (isRight(i) ? 'text-right' : '')}>
                                        {sortKeys[i] ? (
                                            <button onClick={() => sortBy(sortKeys[i])} className={'inline-flex items-end gap-1 uppercase hover:text-navy-700 ' + (isRight(i) ? 'text-right' : 'text-left')}>
                                                {c} {arrow(sortKeys[i])}
                                            </button>
                                        ) : c}
                                    </th>
                                ))}
                                <th className="py-2 pr-3 leading-tight">Status</th>
                                {canWrite && <th className="py-2"></th>}
                            </tr>
                        </thead>
                        <tbody>
                            {rows.data.length === 0 && <tr><td colSpan={columns.length + 4} className="py-10 text-center text-slate-400">No records for this filter.</td></tr>}
                            {rows.data.map((r, ri) => (
                                <tr key={r.id} className={'border-b last:border-0 hover:bg-slate-200 ' + (selected.includes(r.id) ? 'bg-primary-50' : '')}>
                                    {showChecks && <td className="py-2 pr-3"><input type="checkbox" className="rounded border-slate-300 text-primary-600 focus:ring-primary-500" checked={selected.includes(r.id)} onChange={() => toggle(r.id)} /></td>}
                                    <td className="whitespace-nowrap py-2 pr-3 text-slate-400">{(rows.from || 1) + ri}</td>
                                    {r.cells.map((cell, i) => <td key={i} className={'whitespace-nowrap py-2 pr-3 ' + (isRight(i) ? 'text-right tabular-nums' : '')}>{cell}</td>)}
                                    <td className="py-2 pr-3">
                                        {r.settle && canWrite ? (
                                            <button onClick={() => openSettle(r.settle)} title="Click to collect / pay / edit paid amount"
                                                className={'rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset ring-transparent transition hover:ring-slate-300 ' + (statusStyle[r.status] || 'bg-slate-100 text-slate-600')}>
                                                {r.status} ✎
                                            </button>
                                        ) : r.status ? (
                                            <span className={'rounded-full px-2 py-0.5 text-xs font-semibold ' + (statusStyle[r.status] || 'bg-slate-100 text-slate-600')}>{r.status}</span>
                                        ) : (
                                            <span className="text-slate-300">—</span>
                                        )}
                                    </td>
                                    {canWrite && (
                                        <td className="py-2 whitespace-nowrap text-right">
                                            {type === 'credits'
                                                ? (r.settle && r.settle.outstanding > 0
                                                    ? <button onClick={() => openSettle(r.settle)} className="font-semibold text-primary-600 hover:underline">Receive</button>
                                                    : <span className="text-slate-300">Paid</span>)
                                                : (actionLabel && r.action_url
                                                    ? <Link href={r.action_url} className="font-semibold text-primary-600 hover:underline">{actionLabel}</Link>
                                                    : <span className="text-slate-300">—</span>)}
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                        {totals && rows.data.length > 0 && (
                            <tfoot>
                                <tr className="border-t-2 border-navy-800 bg-navy-800 font-bold text-white">
                                    {showChecks && <td className="py-3 pr-3"></td>}
                                    <td className="whitespace-nowrap py-3 pr-3">Total</td>
                                    {totals.map((cell, i) => <td key={i} className={'whitespace-nowrap py-3 pr-3 ' + (isRight(i) ? 'text-right tabular-nums' : '')}>{cell}</td>)}
                                    <td className="py-3 pr-3"></td>
                                    {canWrite && <td className="py-3"></td>}
                                </tr>
                            </tfoot>
                        )}
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

            {/* Bulk payment / return modal */}
            {payOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                    <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                        <h3 className="text-lg font-semibold text-navy-800">{isBorrowed ? 'Bulk Return' : 'Bulk Payment'} — {selected.length} entries</h3>
                        <p className="mt-1 text-xs text-slate-500">The amount is distributed across the selected entries, oldest first (FIFO).</p>
                        <form onSubmit={submitPay} className="mt-4 space-y-3">
                            <label className="block">
                                <span className="mb-1 block text-xs font-medium text-slate-600">Total {isBorrowed ? 'Return' : 'Payment'} Amount</span>
                                <input type="number" step="0.01" className={input + ' w-full'} value={pay.data.amount} onChange={(e) => pay.setData('amount', e.target.value)} required />
                                {pay.errors.amount && <span className="mt-1 block text-xs text-accent-red">{pay.errors.amount}</span>}
                            </label>
                            <div className="grid grid-cols-2 gap-3">
                                <label className="block">
                                    <span className="mb-1 block text-xs font-medium text-slate-600">Date</span>
                                    <input type="date" className={input + ' w-full'} value={pay.data.payment_date} onChange={(e) => pay.setData('payment_date', e.target.value)} />
                                </label>
                                <label className="block">
                                    <span className="mb-1 block text-xs font-medium text-slate-600">Via</span>
                                    <select className={input + ' w-full'} value={pay.data.payment_method_id} onChange={(e) => pay.setData('payment_method_id', e.target.value)}>
                                        <option value="">—</option>
                                        {paymentMethods.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                                    </select>
                                </label>
                            </div>
                            <label className="block">
                                <span className="mb-1 block text-xs font-medium text-slate-600">Note</span>
                                <input className={input + ' w-full'} value={pay.data.note} onChange={(e) => pay.setData('note', e.target.value)} />
                            </label>
                            {pay.errors.bulk && <p className="rounded bg-red-50 p-2 text-xs text-accent-red-dark">{pay.errors.bulk}</p>}
                            <div className="flex gap-2 pt-1">
                                <button disabled={pay.processing} className="flex-1 rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50">Apply</button>
                                <button type="button" onClick={() => setPayOpen(false)} className="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Settle dialog — collect / pay / edit paid amount (from the status badge) */}
            {settleRow && settleRow.kind === 'credit' && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                    <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                        <h3 className="text-lg font-semibold text-navy-800">Receive Payment</h3>
                        <p className="mt-1 text-sm text-slate-500">{settleRow.label}</p>
                        <div className="mt-2 grid grid-cols-3 gap-2 rounded-lg bg-slate-50 p-2 text-center text-xs">
                            <div><div className="text-slate-400">Credit</div><div className="font-semibold">{money(settleRow.credit, settleRow.currency)}</div></div>
                            <div><div className="text-slate-400">Received</div><div className="font-semibold text-emerald-700">{money(settleRow.credit - settleRow.outstanding, settleRow.currency)}</div></div>
                            <div><div className="text-slate-400">Outstanding</div><div className="font-semibold text-accent-red">{money(settleRow.outstanding, settleRow.currency)}</div></div>
                        </div>

                        {settleRow.outstanding > 0 && (
                            <form onSubmit={submitReceive} className="mt-3 space-y-3">
                                <label className="block">
                                    <span className="mb-1 block text-xs font-medium text-slate-600">Amount received <span className="text-slate-400">(less = partial)</span></span>
                                    <input type="number" step="0.01" max={settleRow.outstanding} className={input + ' w-full'} value={rcv.data.amount} onChange={(e) => rcv.setData('amount', e.target.value)} required />
                                    {rcv.errors.amount && <span className="mt-1 block text-xs text-accent-red">{rcv.errors.amount}</span>}
                                </label>
                                <div className="grid grid-cols-2 gap-3">
                                    <label className="block">
                                        <span className="mb-1 block text-xs font-medium text-slate-600">Date</span>
                                        <input type="date" className={input + ' w-full'} value={rcv.data.payment_date} onChange={(e) => rcv.setData('payment_date', e.target.value)} />
                                    </label>
                                    <label className="block">
                                        <span className="mb-1 block text-xs font-medium text-slate-600">Via</span>
                                        <select className={input + ' w-full'} value={rcv.data.payment_method_id} onChange={(e) => rcv.setData('payment_method_id', e.target.value)}>
                                            <option value="">—</option>
                                            {paymentMethods.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                                        </select>
                                        {rcv.errors.payment_method_id && <span className="mt-1 block text-xs text-accent-red">{rcv.errors.payment_method_id}</span>}
                                    </label>
                                </div>
                                <button disabled={rcv.processing} className="w-full rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50">Receive Payment</button>
                            </form>
                        )}

                        {/* Payment history — reverse to edit the paid amount */}
                        {settleRow.payments.length > 0 && (
                            <div className="mt-4">
                                <p className="mb-1 text-xs font-semibold uppercase text-slate-400">Payments received</p>
                                <ul className="space-y-1 text-sm">
                                    {settleRow.payments.map((p) => (
                                        <li key={p.id} className="flex items-center justify-between rounded bg-slate-50 px-2 py-1">
                                            <span>{p.date} · {money(p.amount, settleRow.currency)} <span className="text-xs text-slate-400">{p.method}</span></span>
                                            <button onClick={() => reversePayment(p.id)} className="text-xs text-accent-red hover:underline">Reverse</button>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        <button type="button" onClick={closeSettle} className="mt-4 w-full rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">Close</button>
                    </div>
                </div>
            )}

            {settleRow && settleRow.kind === 'ledger' && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                    <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                        <h3 className="text-lg font-semibold text-navy-800">Update Paid / Returned</h3>
                        <p className="mt-1 text-sm text-slate-500">{settleRow.label}</p>
                        <form onSubmit={submitLedgerSettle} className="mt-3 space-y-3">
                            <div className="grid grid-cols-2 gap-2 rounded-lg bg-slate-50 p-2 text-center text-xs">
                                <div><div className="text-slate-400">Total</div><div className="font-semibold">{money(settleRow.total, settleRow.currency)}</div></div>
                                <div><div className="text-slate-400">Balance</div><div className="font-semibold text-accent-red">{money(ledBalance, settleRow.currency)}</div></div>
                            </div>
                            <label className="block">
                                <span className="mb-1 block text-xs font-medium text-slate-600">Paid / Returned amount <span className="text-slate-400">(set the new total received)</span></span>
                                <input type="number" step="0.01" className={input + ' w-full'} value={led.data.paid_amount} onChange={(e) => led.setData('paid_amount', e.target.value)} required />
                                {led.errors.paid_amount && <span className="mt-1 block text-xs text-accent-red">{led.errors.paid_amount}</span>}
                            </label>
                            <div className="flex gap-2 pt-1">
                                <button disabled={led.processing} className="flex-1 rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50">Save</button>
                                <button type="button" onClick={closeSettle} className="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
