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

export default function Operations({ tabs, type, columns, rows, filters, sort, sortKeys = [], align = null, totals = null, isLedger, statusOptions, actionLabel, bulkDeletable, bulkPayable, paymentMethods, banks = [], companyBanks = [] }) {
    const role = usePage().props.auth.user.role;
    const canWrite = ['super_admin', 'admin', 'accountant'].includes(role);
    const canBulkDelete = ['super_admin', 'admin'].includes(role);
    const isCreditPayable = bulkPayable && !isLedger; // Transactions / Credits tabs — bulk-collect outstanding credit
    const showChecks = (bulkDeletable || bulkPayable) && canWrite;
    const isBorrowed = type === 'borrowed';
    const isRight = (i) => (align ? !!align[i] : i >= 4);

    const [f, setF] = useState(filters);
    const [selected, setSelected] = useState([]);
    const [payOpen, setPayOpen] = useState(false);
    const [settleRow, setSettleRow] = useState(null); // row.settle {kind:'credit'|'ledger', ...}
    const [statementFor, setStatementFor] = useState(null); // { customer_id, customer } — Credits tab only
    const [statementBankId, setStatementBankId] = useState(companyBanks.find((b) => b.is_default)?.id ?? companyBanks[0]?.id ?? '');
    const [statementDate, setStatementDate] = useState(todayLocalISO());
    const [statementInvoiceNo, setStatementInvoiceNo] = useState('');
    const [statementPaymentMode, setStatementPaymentMode] = useState('');
    const debounceTimer = useRef(null);
    const skipNextAutoSearch = useRef(true); // don't re-fire on mount (e.g. a pagination/sort click just navigated here)

    // A top scrollbar mirroring the table's own — on a long list the bottom
    // one (below every row) is too far away to be useful for scrolling right.
    const topScrollRef = useRef(null);
    const tableScrollRef = useRef(null);
    const [tableWidth, setTableWidth] = useState(0);
    useEffect(() => {
        const el = tableScrollRef.current;
        if (!el) return;
        const measure = () => setTableWidth(el.scrollWidth);
        measure();
        const ro = new ResizeObserver(measure);
        ro.observe(el);
        return () => ro.disconnect();
    }, [rows, columns, type]);
    const syncScroll = (from, to) => { if (to.current && from.current) to.current.scrollLeft = from.current.scrollLeft; };

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

    // What the selection actually adds up to, so the bulk dialog can say how
    // much is collectable before an amount is typed. Rows with no settleable
    // balance (a transaction sold without credit) carry no settle payload and
    // so contribute nothing — the same ones the FIFO run skips.
    const selectedSettles = rows.data.filter((r) => selected.includes(r.id) && r.settle).map((r) => r.settle);
    const selectedCurrencies = [...new Set(selectedSettles.map((s) => s.currency || 'AED'))];
    const selectedCurrency = selectedCurrencies.length === 1 ? selectedCurrencies[0] : 'AED';
    const payTotals = selectedSettles.reduce((a, s) => {
        const total = s.kind === 'ledger' ? s.total : s.credit;
        const settled = s.kind === 'ledger' ? s.paid : s.credit - s.outstanding;
        return { total: a.total + total, settled: a.settled + settled, outstanding: a.outstanding + Math.max(0, total - settled) };
    }, { total: 0, settled: 0, outstanding: 0 });
    const payableCount = selectedSettles.filter((s) => (s.kind === 'ledger' ? s.total - s.paid : s.outstanding) > 0).length;

    const pay = useForm({ mode: 'fifo', amount: '', payment_date: todayLocalISO(), payment_method_id: '', bank_id: '', note: '', entry_ids: [] });
    const submitPay = (e) => {
        e.preventDefault();
        const url = isCreditPayable ? route('credits.bulk-store') : route('bulk.store', type);
        const idsKey = isCreditPayable ? 'transaction_ids' : 'entry_ids';
        router.post(url, { ...pay.data, [idsKey]: selected }, {
            preserveScroll: true,
            onSuccess: () => { setPayOpen(false); setSelected([]); pay.reset(); },
        });
    };
    const payMethod = paymentMethods.find((m) => String(m.id) === String(pay.data.payment_method_id));
    const payIsBank = payMethod?.type === 'bank';
    const setPayMethod = (id) => {
        const method = paymentMethods.find((m) => String(m.id) === String(id));
        pay.setData({ ...pay.data, payment_method_id: id, bank_id: method?.type === 'bank' ? pay.data.bank_id : '' });
    };

    // Settle dialog (click a status badge): collect a payment, or edit the paid amount.
    const rcv = useForm({ transaction_id: null, amount: '', payment_date: todayLocalISO(), payment_method_id: '', bank_id: '', note: '' });
    const led = useForm({ paid_amount: '' });
    const openSettle = (s) => {
        setSettleRow(s);
        if (s.kind === 'credit') rcv.setData({ transaction_id: s.id, amount: s.outstanding > 0 ? s.outstanding : '', payment_date: todayLocalISO(), payment_method_id: '', bank_id: '', note: '' });
        else led.setData({ paid_amount: s.paid });
    };
    const rcvMethod = paymentMethods.find((m) => String(m.id) === String(rcv.data.payment_method_id));
    const rcvIsBank = rcvMethod?.type === 'bank';
    const setRcvMethod = (id) => {
        const method = paymentMethods.find((m) => String(m.id) === String(id));
        rcv.setData({ ...rcv.data, payment_method_id: id, bank_id: method?.type === 'bank' ? rcv.data.bank_id : '' });
    };
    const closeSettle = () => setSettleRow(null);
    const submitReceive = (e) => { e.preventDefault(); rcv.post(route('credits.store'), { preserveScroll: true, onSuccess: closeSettle }); };
    const reversePayment = (pid) => { if (confirm('Reverse this payment?')) router.delete(route('credits.payment.destroy', pid), { preserveScroll: true, onSuccess: closeSettle }); };
    const submitLedgerSettle = (e) => { e.preventDefault(); led.put(route('ledger.settle', [settleRow.slug, settleRow.id]), { preserveScroll: true, onSuccess: closeSettle }); };
    const ledBalance = Math.max(0, (settleRow?.total || 0) - (parseFloat(led.data.paid_amount) || 0));

    const openStatement = (row) => setStatementFor(row);
    // The top-of-page button statements the CURRENT filtered set (any customer,
    // possibly many) rather than one row's customer — a different endpoint.
    const openFilteredStatement = () => setStatementFor({ filtered: true, customer: 'Current search results' });
    // Always include bank_id, even empty — that's how the backend tells "no bank
    // section, deliberately chosen" apart from "not specified, use the default".
    const statementExtra = { bank_id: statementBankId, date: statementDate, invoice_no: statementInvoiceNo, payment_mode: statementPaymentMode };
    const statementUrl = statementFor
        ? (statementFor.filtered
            // `filters` (the applied, server-confirmed search/date/status), not
            // `f` (still-being-typed local state) — so the PDF always matches
            // exactly what's currently on screen, not a pending unsent edit.
            ? route('credits.statement.filtered', { ...filters, ...statementExtra })
            : route('credits.statement', { customer: statementFor.customer_id, ...statementExtra }))
        : '#';

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
                    {Object.keys(statusOptions || {}).length > 0 && (
                        <label className="block">
                            <span className="mb-1 block text-[11px] font-medium text-slate-500">Status</span>
                            <select className={input} value={f.status || ''} onChange={(e) => setF({ ...f, status: e.target.value })}>
                                <option value="">All</option>
                                {Object.entries(statusOptions).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                            </select>
                        </label>
                    )}
                    <button type="button" onClick={reset} className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50">Reset</button>
                    {type === 'credits' && (
                        <button type="button" onClick={openFilteredStatement} className="ml-auto rounded-lg bg-navy-700 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800">
                            ⬇ Statement (filtered results)
                        </button>
                    )}
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
                    {(isLedger || isCreditPayable) && canWrite && (
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
                <div ref={topScrollRef} className="scroll-x-visible" onScroll={() => syncScroll(topScrollRef, tableScrollRef)}>
                    <div style={{ width: tableWidth, height: 1 }} />
                </div>
                <div ref={tableScrollRef} className="scroll-x-visible" onScroll={() => syncScroll(tableScrollRef, topScrollRef)}>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left text-xs uppercase text-slate-500 align-bottom">
                                {showChecks && <th className="py-2 pr-3"><input type="checkbox" className="rounded border-slate-300 text-primary-600 focus:ring-primary-500" checked={allChecked} onChange={toggleAll} /></th>}
                                <th className="py-2 pr-3 leading-tight">Sl No</th>
                                <th className="py-2 pr-1 leading-tight"></th>
                                {columns.map((c, i) => (
                                    <th key={c} className={'py-2 pr-3 leading-tight ' + (isRight(i) ? 'text-right ' : '') + (c === 'Total Amount' ? 'bg-navy-800 text-white font-bold' : '')}>
                                        {sortKeys[i] ? (
                                            <button onClick={() => sortBy(sortKeys[i])} className={'inline-flex items-end gap-1 uppercase hover:text-navy-700 ' + (isRight(i) ? 'text-right' : 'text-left') + (c === 'Total Amount' ? ' hover:text-white' : '')}>
                                                {c} {arrow(sortKeys[i])}
                                            </button>
                                        ) : c}
                                    </th>
                                ))}
                                <th className="py-2 pr-3 leading-tight">Status</th>
                                {type === 'credits' && <th className="py-2 pr-3"></th>}
                                {canWrite && <th className="py-2"></th>}
                            </tr>
                        </thead>
                        <tbody>
                            {rows.data.length === 0 && <tr><td colSpan={columns.length + 5} className="py-10 text-center text-slate-400">No records for this filter.</td></tr>}
                            {rows.data.map((r, ri) => (
                                <tr key={r.id} className={'border-b last:border-0 hover:bg-slate-200 ' + (selected.includes(r.id) ? 'bg-primary-50' : '')}>
                                    {showChecks && <td className="py-2 pr-3"><input type="checkbox" className="rounded border-slate-300 text-primary-600 focus:ring-primary-500" checked={selected.includes(r.id)} onChange={() => toggle(r.id)} /></td>}
                                    <td className="whitespace-nowrap py-2 pr-3 text-slate-400">{(rows.from || 1) + ri}</td>
                                    <td className="whitespace-nowrap py-2 pr-1">
                                        {r.bank_missing && (
                                            <span title="Bank payment recorded without a bank selected — edit this row to pick the correct bank." className="text-amber-500">⚠</span>
                                        )}
                                    </td>
                                    {r.cells.map((cell, i) => <td key={i} className={'whitespace-nowrap py-2 pr-3 ' + (isRight(i) ? 'text-right tabular-nums ' : '') + (columns[i] === 'Total Amount' ? 'bg-navy-800 text-white font-bold' : '')}>{cell}</td>)}
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
                                    {type === 'credits' && (
                                        <td className="py-2 pr-3 whitespace-nowrap text-right">
                                            <button onClick={() => openStatement(r)} className="text-navy-600 hover:underline">Statement</button>
                                        </td>
                                    )}
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
                                    <td className="py-3 pr-1"></td>
                                    {totals.map((cell, i) => <td key={i} className={'whitespace-nowrap py-3 pr-3 ' + (isRight(i) ? 'text-right tabular-nums' : '')}>{cell}</td>)}
                                    <td className="py-3 pr-3"></td>
                                    {type === 'credits' && <td className="py-3 pr-3"></td>}
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
                        <h3 className="text-lg font-semibold text-navy-800">{isBorrowed ? 'Bulk Return' : 'Bulk Payment'} — {selected.length} {isCreditPayable ? 'invoices' : 'entries'}</h3>
                        <p className="mt-1 text-xs text-slate-500">The amount is distributed across the selected {isCreditPayable ? 'invoices' : 'entries'}, oldest first (FIFO). Rows with nothing outstanding are skipped automatically.</p>
                        <div className="mt-3 grid grid-cols-3 gap-2 rounded-lg bg-slate-50 p-2 text-center text-xs">
                            <div><div className="text-slate-400">{isCreditPayable ? 'Credit' : 'Total'}</div><div className="font-semibold">{money(payTotals.total, selectedCurrency)}</div></div>
                            <div><div className="text-slate-400">{isCreditPayable ? 'Received' : isBorrowed ? 'Returned' : 'Paid'}</div><div className="font-semibold text-emerald-700">{money(payTotals.settled, selectedCurrency)}</div></div>
                            <div><div className="text-slate-400">{isCreditPayable ? 'Outstanding' : 'Balance'}</div><div className="font-semibold text-accent-red">{money(payTotals.outstanding, selectedCurrency)}</div></div>
                        </div>
                        {payableCount < selected.length && (
                            <p className="mt-1 text-center text-[11px] text-slate-400">{payableCount} of {selected.length} selected {payableCount === 1 ? 'row carries' : 'rows carry'} a balance — the rest are already settled.</p>
                        )}
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
                                    <select className={input + ' w-full'} value={pay.data.payment_method_id} onChange={(e) => setPayMethod(e.target.value)}>
                                        <option value="">—</option>
                                        {paymentMethods.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                                    </select>
                                </label>
                            </div>
                            {payIsBank && (
                                <label className="block">
                                    <span className="mb-1 block text-xs font-medium text-slate-600">Bank Account</span>
                                    <select className={input + ' w-full'} value={pay.data.bank_id} onChange={(e) => pay.setData('bank_id', e.target.value)}>
                                        <option value="">Select bank…</option>
                                        {banks.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                                    </select>
                                    {pay.errors.bank_id && <span className="mt-1 block text-xs text-accent-red">{pay.errors.bank_id}</span>}
                                </label>
                            )}
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
                                        <select className={input + ' w-full'} value={rcv.data.payment_method_id} onChange={(e) => setRcvMethod(e.target.value)}>
                                            <option value="">—</option>
                                            {paymentMethods.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                                        </select>
                                        {rcv.errors.payment_method_id && <span className="mt-1 block text-xs text-accent-red">{rcv.errors.payment_method_id}</span>}
                                    </label>
                                </div>
                                {rcvIsBank && (
                                    <label className="block">
                                        <span className="mb-1 block text-xs font-medium text-slate-600">Bank Account</span>
                                        <select className={input + ' w-full'} value={rcv.data.bank_id} onChange={(e) => rcv.setData('bank_id', e.target.value)}>
                                            <option value="">Select bank…</option>
                                            {banks.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                                        </select>
                                        {rcv.errors.bank_id && <span className="mt-1 block text-xs text-accent-red">{rcv.errors.bank_id}</span>}
                                    </label>
                                )}
                                <button disabled={rcv.processing} className="w-full rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50">Receive Payment</button>
                            </form>
                        )}

                        {/* Payment history — reverse to edit the paid amount */}
                        {settleRow.payments.length > 0 && (
                            <div className="mt-4">
                                <p className="mb-1 text-xs font-semibold uppercase text-slate-400">Payments received</p>
                                <ul className="space-y-1 text-sm">
                                    {settleRow.payments.map((p) => (
                                        <li key={p.id} className="flex items-start justify-between gap-2 rounded bg-slate-50 px-2 py-1">
                                            <span>
                                                {p.date} · {money(p.amount, settleRow.currency)} <span className="text-xs text-slate-400">{p.method}</span>
                                                {p.bank_missing && <span title="No bank selected for this bank payment — reverse and re-record it with the correct bank." className="ml-1 text-amber-500">⚠</span>}
                                                {p.note && <span className="mt-0.5 block text-xs italic text-slate-500">{p.note}</span>}
                                            </span>
                                            <button onClick={() => reversePayment(p.id)} className="shrink-0 text-xs text-accent-red hover:underline">Reverse</button>
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

                        {/* Payment history — read-only here; the amount above is the editable figure */}
                        {settleRow.payments?.length > 0 && (
                            <div className="mt-4">
                                <p className="mb-1 text-xs font-semibold uppercase text-slate-400">{isBorrowed ? 'Returns recorded' : 'Payments received'}</p>
                                <ul className="space-y-1 text-sm">
                                    {settleRow.payments.map((p) => (
                                        <li key={p.id} className="rounded bg-slate-50 px-2 py-1">
                                            {p.date} · {money(p.amount, settleRow.currency)} <span className="text-xs text-slate-400">{p.method}</span>
                                            {p.bank_missing && <span title="No bank selected for this bank payment." className="ml-1 text-amber-500">⚠</span>}
                                            {p.note && <span className="mt-0.5 block text-xs italic text-slate-500">{p.note}</span>}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </div>
                </div>
            )}

            {statementFor && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                    <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                        <h3 className="text-lg font-semibold text-navy-800">Download Outstanding Statement</h3>
                        <p className="mt-1 text-sm text-slate-500">{statementFor.customer}</p>
                        {statementFor.filtered && (filters.search || filters.from || filters.to || filters.status) && (
                            <p className="mt-1 text-xs text-slate-400">
                                {filters.search && <>Search: “{filters.search}” </>}
                                {(filters.from || filters.to) && <>· {filters.from || '…'} → {filters.to || '…'} </>}
                                {filters.status && <>· Status: {statusOptions[filters.status] || filters.status}</>}
                            </p>
                        )}

                        <label className="mt-4 block">
                            <span className="mb-1 block text-xs font-medium text-slate-600">Date</span>
                            <input type="date" className={input + ' w-full'} value={statementDate} onChange={(e) => setStatementDate(e.target.value)} />
                        </label>

                        <label className="mt-3 block">
                            <span className="mb-1 block text-xs font-medium text-slate-600">Invoice No (optional)</span>
                            <input className={input + ' w-full'} value={statementInvoiceNo} onChange={(e) => setStatementInvoiceNo(e.target.value)} />
                        </label>

                        <label className="mt-3 block">
                            <span className="mb-1 block text-xs font-medium text-slate-600">Mode of Payment</span>
                            <select className={input + ' w-full'} value={statementPaymentMode} onChange={(e) => setStatementPaymentMode(e.target.value)}>
                                <option value="">—</option>
                                <option value="Cash">Cash</option>
                                <option value="Account">Account</option>
                            </select>
                        </label>

                        <label className="mt-3 block">
                            <span className="mb-1 block text-xs font-medium text-slate-600">Show bank details for</span>
                            <select className={input + ' w-full'} value={statementBankId} onChange={(e) => setStatementBankId(e.target.value)}>
                                <option value="">No bank details</option>
                                {companyBanks.map((b) => <option key={b.id} value={b.id}>{b.bank_name} — {b.account_name}{b.is_default ? ' (default)' : ''}</option>)}
                            </select>
                            {companyBanks.length === 0 && (
                                <span className="mt-1 block text-xs text-slate-400">
                                    No bank accounts saved yet. Add them under Master Data → Bank Payment Details.
                                </span>
                            )}
                        </label>

                        <div className="mt-4 flex gap-2">
                            <a href={statementUrl} target="_blank" rel="noopener noreferrer"
                                onClick={() => setStatementFor(null)}
                                className="flex-1 rounded-lg bg-primary-600 px-4 py-2 text-center text-sm font-semibold text-white shadow hover:bg-primary-700">
                                Download PDF
                            </a>
                            <button type="button" onClick={() => setStatementFor(null)} className="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">Cancel</button>
                        </div>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
