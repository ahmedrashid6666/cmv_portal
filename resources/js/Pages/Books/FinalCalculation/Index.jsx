import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card } from '@/Components/ui/Card';
import { num } from '@/lib/format';
import { computeFinalCalculation } from '@/lib/calc';
import focusNextFieldOnEnter from '@/lib/focusNextFieldOnEnter';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

const EDIT_MODE_STORAGE_KEY = 'finalCalc.editable';

const input = 'w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';
const cell = input + ' text-right tabular-nums px-2 py-1';

// Fixed reconciliation ladder — mirrors the accountant's spreadsheet exactly.
const ROWS = [
    { key: 'opening_balance', label: 'Opening Balance' },
    { key: 'total_income', label: 'Total Income' },
    { key: 'customs_gov_fees', label: 'Total Customs/Gov. Fees Paid', negative: true },
    { key: 'credit_unpaid', label: 'Total Credit (Unpaid)', negative: true },
    { key: 'office_expenses', label: 'Office Expenses', negative: true },
    { key: 'total', label: 'TOTAL AMOUNT', total: 'total_amount', tone: 'yellow' },
    { key: 'borrowed_amount', label: 'Borrowed Amount' },
    { key: 'daily_credit_pending', label: 'Daily Credit (Pending)', negative: true },
    { key: 'total', label: 'TOTAL BALANCE AMOUNT', total: 'total_balance_amount', tone: 'blue' },
    { key: 'bank_ac_balance', label: 'All Bank A/C Balance', negative: true },
    { key: 'cdr_ac_balance', label: 'CDR A/C Balance', negative: true },
    { key: 'total', label: 'TOTAL CASH BALANCE IN HAND', total: 'total_cash_balance', tone: 'green' },
];

const TONE = {
    green: 'bg-emerald-100 text-emerald-900',
    blue: 'bg-sky-100 text-sky-900',
    yellow: 'bg-amber-100 text-amber-900',
};

export default function FinalCalculation({ date, data, totals, saved, savedId, defaultOmrRate, denominations, count, history }) {
    const role = usePage().props.auth.user.role;
    const canWrite = ['super_admin', 'admin', 'accountant'].includes(role);

    const [editable, setEditableState] = useState(() => {
        try {
            return localStorage.getItem(EDIT_MODE_STORAGE_KEY) === 'true';
        } catch {
            return false;
        }
    });
    const setEditable = (v) => {
        setEditableState(v);
        try {
            localStorage.setItem(EDIT_MODE_STORAGE_KEY, String(v));
        } catch {
            // localStorage unavailable — edit mode just won't persist across reloads.
        }
    };

    const { data: form, setData, post, processing, errors } = useForm({
        calc_date: date,
        data: { omr_rate: defaultOmrRate, ...data },
        remarks: data.remarks ?? '',
    });

    const setField = (key, value) => setData('data', { ...form.data, [key]: value });

    // Mirror of FinalCalculationService::compute — on-screen totals must match the save.
    const t = useMemo(() => computeFinalCalculation(form.data, defaultOmrRate), [form.data, defaultOmrRate]);
    const extra = t.cash_extra;

    const changeDate = (d) => router.get(route('final-calc.index'), { date: d }, { preserveState: false });
    const recompute = () => router.get(route('final-calc.index'), { date: form.calc_date, fresh: 1 }, { preserveState: false });
    const save = (e) => {
        e.preventDefault();
        setData('remarks', form.data.remarks);
        post(route('final-calc.store'), { preserveScroll: true });
    };
    const deleteSnapshot = (h) => {
        if (confirm(`Delete the Final Calculation snapshot for ${h.date}?`)) {
            router.delete(route('final-calc.destroy', h.id), { preserveScroll: true });
        }
    };

    return (
        <AuthenticatedLayout header="Final Calculation">
            <Head title="Final Calculation" />

            <div onKeyDown={focusNextFieldOnEnter}>
                <div className="mb-4 flex flex-wrap items-end gap-3">
                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-slate-600">Date</span>
                        <input type="date" className={input + ' w-44'} value={form.calc_date}
                            onChange={(e) => { setData('calc_date', e.target.value); changeDate(e.target.value); }} />
                    </label>
                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-slate-600">OMR → AED rate</span>
                        <input type="number" step="0.0001" className={input + ' w-32 text-right'} value={form.data.omr_rate ?? defaultOmrRate}
                            onChange={(e) => setField('omr_rate', e.target.value)} />
                    </label>
                    <div className="flex-1" />
                    <EditModeToggle editable={editable} setEditable={setEditable} />
                    <button type="button" onClick={recompute} disabled={!editable} className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-navy-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50">
                        Recompute from live
                    </button>
                    {saved && (
                        <a href={route('final-calc.pdf', savedId)} target="_blank" className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-navy-700 hover:bg-slate-50">
                            Print / PDF
                        </a>
                    )}
                    <button onClick={save} disabled={processing || !editable} className="rounded-lg bg-primary-600 px-5 py-2 text-sm font-semibold text-white shadow hover:bg-primary-700 disabled:opacity-50">
                        {saved ? 'Update' : 'Save'}
                    </button>
                </div>

                {Object.keys(errors).length > 0 && (
                    <div className="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-accent-red">
                        {Object.values(errors).map((msg, i) => <div key={i}>{msg}</div>)}
                    </div>
                )}

                <div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
                    <Card className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="bg-[#f6d9c3] text-left text-xs font-bold uppercase text-navy-900">
                                    <th className="px-3 py-2">Details</th>
                                    <th className="px-3 py-2 text-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                {ROWS.map((row, i) => (
                                    <DetailRow key={row.total ?? row.key} row={row} idx={i} t={t} form={form} editable={editable} setField={setField} />
                                ))}
                            </tbody>
                        </table>
                    </Card>

                    <CashCountWidget date={form.calc_date} denominations={denominations} count={count}
                        onSaved={(counted) => setData('data', { ...form.data, aed_counted: counted.aed_counted, omr_counted: counted.omr_counted })} />
                </div>

                {/* Reconciliation */}
                <div className="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
                    <Stat label="Total Cash Balance In Hand" value={num(t.total_cash_balance)} accent="text-emerald-700" big />
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs uppercase text-slate-500">Cash Counted</p>
                        <div className="mt-1 flex flex-wrap items-baseline gap-x-4 gap-y-1 text-lg font-bold text-navy-900">
                            <span>{num(t.aed_counted)} <Tag>AED</Tag></span>
                            <span>OMR {num(t.omr_counted)} <span className="text-xs font-normal text-slate-400">(AED {num(t.cash_omr_as_aed)})</span></span>
                        </div>
                        <div className="mt-2 flex items-baseline gap-2 border-t border-slate-100 pt-2">
                            <span className="text-xs uppercase text-slate-500">Total</span>
                            <span className="text-2xl font-bold text-navy-900">{num(t.cash_counted)} <Tag>AED</Tag></span>
                        </div>
                    </div>
                    <div className={'rounded-xl border p-4 shadow-sm ' + (extra === 0 ? 'border-emerald-200 bg-emerald-50' : extra > 0 ? 'border-amber-200 bg-amber-50' : 'border-red-200 bg-red-50')}>
                        <p className="text-xs uppercase text-slate-500">Cash Extra</p>
                        <p className={'mt-1 text-2xl font-bold ' + (extra === 0 ? 'text-emerald-700' : extra > 0 ? 'text-amber-600' : 'text-accent-red')}>
                            {extra === 0 ? 'Balanced ✓' : `${extra > 0 ? 'Over ' : 'Short '}${num(Math.abs(extra))}`}
                        </p>
                    </div>
                </div>

                <Card className="mt-4">
                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-slate-600">Remarks</span>
                        <textarea rows="2" className={input} value={form.data.remarks ?? ''}
                            onChange={(e) => setField('remarks', e.target.value)} />
                    </label>
                </Card>
            </div>

            <Card title="Recent Snapshots" className="mt-4">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left text-xs uppercase text-slate-500">
                                <th className="py-2 pr-4">Date</th>
                                <th className="py-2 pr-4 text-right">Total Cash Balance</th>
                                <th className="py-2 pr-4 text-right">Cash Extra</th>
                                <th className="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {history.length === 0 && <tr><td colSpan="4" className="py-6 text-center text-slate-400">No snapshots saved yet.</td></tr>}
                            {history.map((h) => (
                                <tr key={h.id} className="border-b last:border-0 hover:bg-slate-200">
                                    <td className="py-2 pr-4">{h.date}</td>
                                    <td className="py-2 pr-4 text-right tabular-nums">{num(h.total_cash_balance)}</td>
                                    <td className={'py-2 pr-4 text-right font-semibold tabular-nums ' + (h.cash_extra === 0 ? 'text-emerald-700' : h.cash_extra > 0 ? 'text-amber-600' : 'text-accent-red')}>
                                        {h.cash_extra > 0 ? '+' : ''}{num(h.cash_extra)}
                                    </td>
                                    <td className="py-2 text-right whitespace-nowrap">
                                        <a href={route('final-calc.pdf', h.id)} target="_blank" className="text-primary-600 hover:underline">PDF</a>
                                        <button onClick={() => changeDate(h.date)} className="ml-3 text-navy-600 hover:underline">Edit</button>
                                        {canWrite && <button onClick={() => deleteSnapshot(h)} className="ml-3 text-accent-red hover:underline">Delete</button>}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </Card>
        </AuthenticatedLayout>
    );
}

function DetailRow({ row, t, form, editable, setField }) {
    if (row.total) {
        return (
            <tr className={'font-bold ' + TONE[row.tone]}>
                <td className="px-3 py-2">{row.label}</td>
                <td className="px-3 py-2 text-right tabular-nums">{num(t[row.total])}</td>
            </tr>
        );
    }

    return (
        <tr className="border-b border-slate-100">
            <td className="px-3 py-2 text-navy-800">{row.label}</td>
            <td className="px-2 py-1">
                <div className="flex items-center justify-end gap-1">
                    {row.negative && <span className="text-xs text-slate-400">−</span>}
                    <input type="number" step="0.01"
                        className={cell + ' disabled:border-slate-200 disabled:bg-slate-50 disabled:text-navy-800'}
                        value={form.data[row.key] ?? ''} disabled={!editable}
                        onChange={(e) => setField(row.key, e.target.value)} />
                </div>
            </td>
        </tr>
    );
}

// The AED/OMR denomination + bundle count, relocated from the Daily Cash
// Count page. Saves independently via its own "Save Count" action — see
// docs/superpowers/specs/2026-08-06-final-calculation-redesign-design.md.
function CashCountWidget({ date, denominations, count, onSaved }) {
    const { data, setData, post, processing } = useForm({
        count_date: date,
        lines: count?.lines ?? { AED: {}, OMR: {} },
        // extras/remarks aren't edited here (they live on the Daily Cash
        // Count page) but must round-trip unchanged on save.
        extras: count?.extras ?? { AED: [], OMR: [] },
        bundles: count?.bundles ?? { AED: [], OMR: [] },
        remarks: count?.remarks ?? '',
    });

    // Inertia caches page props in browser history state, so an instance
    // restored via the Back button can be holding a stale `count`. Force a
    // fresh partial reload on mount so we never save over newer data saved
    // elsewhere (e.g. on the Daily Cash Count page) in the meantime.
    useEffect(() => {
        router.reload({ only: ['count'] });
    }, []);

    useEffect(() => {
        setData('lines', count?.lines ?? { AED: {}, OMR: {} });
        setData('extras', count?.extras ?? { AED: [], OMR: [] });
        setData('bundles', count?.bundles ?? { AED: [], OMR: [] });
        setData('remarks', count?.remarks ?? '');
    }, [count]);

    const setQty = (cur, denom, qty) => setData('lines', { ...data.lines, [cur]: { ...data.lines[cur], [denom]: qty } });

    const updateBundle = (cur, idx, key, value) => {
        const bundles = (data.bundles[cur] || []).map((b, i) => (i === idx ? { ...b, [key]: value } : b));
        setData('bundles', { ...data.bundles, [cur]: bundles });
    };
    const addBundle = (cur) => {
        const bundles = data.bundles[cur] || [];
        setData('bundles', { ...data.bundles, [cur]: [...bundles, { label: `Bundle-${bundles.length + 1}`, amount: '' }] });
    };
    const removeBundle = (cur, idx) => setData('bundles', { ...data.bundles, [cur]: (data.bundles[cur] || []).filter((_, i) => i !== idx) });

    // Denomination-only subtotal — shown as the card's header badge.
    const denomTotal = (cur) => {
        let sum = 0;
        for (const d of denominations[cur]) sum += d * (parseFloat(data.lines[cur]?.[d]) || 0);
        return cur === 'OMR' ? Math.round(sum * 1000) / 1000 : Math.round(sum * 100) / 100;
    };

    // Bundles ARE counted toward the actual reconciliation total (matches
    // CashCount::totalFor on the backend) — only the denomination subtotal
    // above excludes them.
    const bundleTotal = (cur) => (data.bundles[cur] || []).reduce((sum, b) => sum + (parseFloat(b.amount) || 0), 0);
    const countedTotal = (cur) => {
        const sum = denomTotal(cur) + bundleTotal(cur);
        return cur === 'OMR' ? Math.round(sum * 1000) / 1000 : Math.round(sum * 100) / 100;
    };

    const save = () => post(route('cash-count.store'), {
        preserveScroll: true,
        onSuccess: () => onSaved?.({ aed_counted: countedTotal('AED'), omr_counted: countedTotal('OMR') }),
    });

    const rowCount = Math.max(denominations.AED.length, denominations.OMR.length);

    return (
        <div className="space-y-6">
            <Card title="Cash Count" action={
                <div className="flex items-center gap-4 text-sm font-bold text-primary-700">
                    <span>{num(denomTotal('AED'))} <span className="text-xs font-normal text-slate-400">AED</span></span>
                    <span>{num(denomTotal('OMR'))} <span className="text-xs font-normal text-slate-400">OMR</span></span>
                </div>
            }>
                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-b text-left text-xs uppercase text-slate-500">
                            <th className="py-1.5 pr-3">AED Denom.</th>
                            <th className="py-1.5 pr-3 text-center">Qty</th>
                            <th className="py-1.5 pr-3 text-right">Amount</th>
                            <th className="py-1.5 pr-3 pl-4 border-l">OMR Denom.</th>
                            <th className="py-1.5 pr-3 text-center">Qty</th>
                            <th className="py-1.5 text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        {Array.from({ length: rowCount }).map((_, i) => {
                            const dAed = denominations.AED[i];
                            const dOmr = denominations.OMR[i];
                            const qtyAed = dAed !== undefined ? (parseFloat(data.lines.AED?.[dAed]) || 0) : 0;
                            const qtyOmr = dOmr !== undefined ? (parseFloat(data.lines.OMR?.[dOmr]) || 0) : 0;
                            return (
                                <tr key={i} className="border-b last:border-0">
                                    {dAed !== undefined ? (
                                        <>
                                            <td className="py-1 pr-3 font-medium text-navy-800">{num(dAed)}</td>
                                            <td className="py-1 pr-3">
                                                <input type="number" min="0" step="1" className={input + ' text-center'} value={data.lines.AED?.[dAed] ?? ''} onChange={(e) => setQty('AED', dAed, e.target.value)} />
                                            </td>
                                            <td className="py-1 pr-3 text-right tabular-nums text-slate-600">{qtyAed ? num(dAed * qtyAed) : '—'}</td>
                                        </>
                                    ) : (
                                        <>
                                            <td className="py-1 pr-3" />
                                            <td className="py-1 pr-3" />
                                            <td className="py-1 pr-3" />
                                        </>
                                    )}
                                    {dOmr !== undefined ? (
                                        <>
                                            <td className="py-1 pr-3 pl-4 border-l font-medium text-navy-800">{num(dOmr)}</td>
                                            <td className="py-1 pr-3">
                                                <input type="number" min="0" step="1" className={input + ' text-center'} value={data.lines.OMR?.[dOmr] ?? ''} onChange={(e) => setQty('OMR', dOmr, e.target.value)} />
                                            </td>
                                            <td className="py-1 text-right tabular-nums text-slate-600">{qtyOmr ? num(dOmr * qtyOmr) : '—'}</td>
                                        </>
                                    ) : (
                                        <>
                                            <td className="py-1 pr-3 pl-4 border-l" />
                                            <td className="py-1 pr-3" />
                                            <td className="py-1" />
                                        </>
                                    )}
                                </tr>
                            );
                        })}
                    </tbody>
                </table>

                <div className="mt-4 grid grid-cols-1 gap-4 border-t pt-3 sm:grid-cols-2">
                    {['AED', 'OMR'].map((cur) => (
                        <div key={cur}>
                            <div className="mb-1 flex items-center justify-between">
                                <span className="text-xs font-semibold uppercase text-slate-600">{cur} Bundles</span>
                                <button type="button" onClick={() => addBundle(cur)} className="text-xs font-semibold text-primary-600 hover:underline">+ Add Bundle</button>
                            </div>
                            <p className="mb-2 text-[10px] text-slate-400">Not in the {cur} total above — added into Total Counted below.</p>
                            {(data.bundles[cur] || []).length > 0 && (
                                <table className="w-full text-sm">
                                    <tbody>
                                        {(data.bundles[cur] || []).map((b, idx) => (
                                            <tr key={idx} className="border-b last:border-0">
                                                <td className="py-1 pr-3">
                                                    <input type="text" className={input} value={b.label} onChange={(e) => updateBundle(cur, idx, 'label', e.target.value)} />
                                                </td>
                                                <td className="py-1 pr-3 w-36">
                                                    <input type="number" step="0.01" className={input + ' text-right'} placeholder="0.00" value={b.amount} onChange={(e) => updateBundle(cur, idx, 'amount', e.target.value)} />
                                                </td>
                                                <td className="py-1 pl-1 text-center w-6">
                                                    <button type="button" onClick={() => removeBundle(cur, idx)} className="text-accent-red hover:text-accent-red-dark">✕</button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                            <div className="mt-2 flex items-center justify-between border-t border-dashed pt-2 text-xs font-semibold text-navy-800">
                                <span>{cur} Total Counted</span>
                                <span className="tabular-nums">{num(countedTotal(cur))}</span>
                            </div>
                        </div>
                    ))}
                </div>
            </Card>
            <button type="button" onClick={save} disabled={processing} className="w-full rounded-lg bg-primary-600 px-5 py-2 text-sm font-semibold text-white shadow hover:bg-primary-700 disabled:opacity-50">
                Save Cash Count
            </button>
        </div>
    );
}

function EditModeToggle({ editable, setEditable }) {
    const options = [
        { value: true, label: 'Enable editing' },
        { value: false, label: 'Disable editing' },
    ];
    return (
        <fieldset className="flex rounded-lg border border-slate-300 bg-white p-0.5 text-sm">
            <legend className="sr-only">Edit mode</legend>
            {options.map((opt) => (
                <label key={opt.label} className={'cursor-pointer rounded-md px-3 py-1.5 font-semibold transition ' +
                    (editable === opt.value ? 'bg-primary-600 text-white shadow-sm' : 'text-navy-700 hover:bg-slate-50')}>
                    <input type="radio" name="finalCalcEditMode" className="sr-only" checked={editable === opt.value}
                        onChange={() => setEditable(opt.value)} />
                    {opt.label}
                </label>
            ))}
        </fieldset>
    );
}

function Tag({ children }) {
    return <span className="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-500">{children}</span>;
}

function Stat({ label, value, accent, big }) {
    return (
        <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <p className="text-xs uppercase text-slate-500">{label}</p>
            <p className={`mt-1 font-bold ${big ? 'text-3xl' : 'text-2xl'} ${accent}`}>{value}</p>
        </div>
    );
}
