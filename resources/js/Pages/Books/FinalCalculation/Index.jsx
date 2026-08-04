import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card } from '@/Components/ui/Card';
import { num } from '@/lib/format';
import focusNextFieldOnEnter from '@/lib/focusNextFieldOnEnter';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const EDIT_MODE_STORAGE_KEY = 'finalCalc.editable';

const input = 'w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';
const cell = input + ' text-right tabular-nums px-2 py-1';

// Tints that echo the accountant's colour-coded worksheet, kept subtle.
const groupTint = { top: 'bg-white', banks: 'bg-emerald-50/40', other: 'bg-amber-50/40' };

const n = (v) => (v === '' || v === null || v === undefined ? 0 : parseFloat(v) || 0);

export default function FinalCalculation({ date, data, totals, saved, savedId, defaultOmrRate, history }) {
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

    const { data: form, setData, post, processing } = useForm({
        calc_date: date,
        data: {
            rows: (data.rows ?? []).map((r) => ({ ...r })),
            omr_rate: data.omr_rate ?? defaultOmrRate,
            remarks: data.remarks ?? '',
        },
        remarks: data.remarks ?? '',
    });

    const rows = form.data.rows;
    const rate = n(form.data.omr_rate) || defaultOmrRate;

    const setRows = (next) => setData('data', { ...form.data, rows: next });
    const setField = (i, key, value) => setRows(rows.map((r, idx) => (idx === i ? { ...r, [key]: value } : r)));
    const addRow = (group) =>
        setRows([...rows, { key: `m_${Date.now()}`, label: '', group, currency: 'AED', manual: true, auto_field: null }]);
    const removeRow = (i) => setRows(rows.filter((_, idx) => idx !== i));
    const setRate = (v) => setData('data', { ...form.data, omr_rate: v });

    // Mirror of FinalCalculationService::compute — on-screen totals must match the save.
    const t = useMemo(() => {
        const sum = (col) => rows.reduce((s, r) => s + n(r[col]), 0);
        const totalAmount = sum('amount');
        const totalAc = sum('ac_balance');
        const totalDebt = sum('debt_exp');
        const liquid = totalAmount - (totalAc + totalDebt);
        const cashAedTotal = sum('cash_aed');
        const cashOmrTotal = sum('cash_omr');
        const cashCounted = cashAedTotal + cashOmrTotal * rate;
        return {
            total_amount: totalAmount,
            total_ac_balance: totalAc,
            total_debt_exp: totalDebt,
            liquid_cash: liquid,
            cash_aed_total: cashAedTotal,
            cash_omr_total: cashOmrTotal,
            cash_omr_as_aed: cashOmrTotal * rate,
            cash_counted: cashCounted,
            cash_extra: cashCounted - liquid,
        };
    }, [rows, rate]);

    const extra = Math.round(t.cash_extra * 100) / 100;

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
                    <input type="number" step="0.0001" className={input + ' w-32 text-right'} value={form.data.omr_rate}
                        onChange={(e) => setRate(e.target.value)} />
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

            <Card className="overflow-x-auto">
                <table className="w-full min-w-[860px] text-sm">
                    <thead>
                        <tr className="bg-[#f6d9c3] text-left text-xs font-bold uppercase text-navy-900">
                            <th className="px-3 py-2">Final Calculation</th>
                            <th className="px-3 py-2 text-right">Amount</th>
                            <th className="px-3 py-2 text-right">A/C Balance</th>
                            <th className="px-3 py-2 text-right">Debt / Exp</th>
                            <th className="px-3 py-2 text-right">Cash (AED)</th>
                            <th className="px-3 py-2 text-right">Cash (OMR)</th>
                            <th className="px-2 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        {['top', 'banks', 'other'].map((group) => (
                            <RowGroup key={group} group={group} rows={rows} setField={setField} removeRow={removeRow} addRow={addRow} editable={editable} />
                        ))}
                    </tbody>
                    <tfoot>
                        <tr className="border-t-2 border-navy-200 bg-slate-50 font-bold text-navy-900">
                            <td className="px-3 py-2">TOTAL</td>
                            <td className="px-3 py-2 text-right tabular-nums">{num(t.total_amount)}</td>
                            <td className="px-3 py-2 text-right tabular-nums">{num(t.total_ac_balance)}</td>
                            <td className="px-3 py-2 text-right tabular-nums">{num(t.total_debt_exp)}</td>
                            <td className="px-3 py-2 text-right tabular-nums" colSpan={2}>
                                <div className="flex flex-wrap items-baseline justify-end gap-x-3 text-sm">
                                    <span>{num(t.cash_aed_total)} <span className="text-xs font-normal text-slate-400">AED</span></span>
                                    <span>OMR {num(t.cash_omr_total)} <span className="text-xs font-normal text-slate-400">(AED {num(t.cash_omr_as_aed)})</span></span>
                                </div>
                                <div className="mt-1 flex items-baseline justify-end gap-2 border-t border-navy-200 pt-1">
                                    <span className="text-xs font-normal text-slate-500">TOTAL</span>
                                    <span className="text-base">{num(t.cash_counted)} <span className="text-xs font-normal text-slate-400">AED</span></span>
                                </div>
                            </td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </Card>

            {/* Reconciliation */}
            <div className="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
                <Stat label="Total Liquid Cash in CMV" value={num(t.liquid_cash)} accent="text-emerald-700" big />
                <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p className="text-xs uppercase text-slate-500">Cash Counted</p>
                    <div className="mt-1 flex flex-wrap items-baseline gap-x-4 gap-y-1 text-lg font-bold text-navy-900">
                        <span>{num(t.cash_aed_total)} <Tag>AED</Tag></span>
                        <span>OMR {num(t.cash_omr_total)} <span className="text-xs font-normal text-slate-400">(AED {num(t.cash_omr_as_aed)})</span></span>
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
                    <textarea rows="2" className={input} value={form.data.remarks}
                        onChange={(e) => setData('data', { ...form.data, remarks: e.target.value })} />
                </label>
            </Card>
            </div>

            <Card title="Recent Snapshots" className="mt-4">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left text-xs uppercase text-slate-500">
                                <th className="py-2 pr-4">Date</th>
                                <th className="py-2 pr-4 text-right">Liquid Cash</th>
                                <th className="py-2 pr-4 text-right">Cash Extra</th>
                                <th className="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {history.length === 0 && <tr><td colSpan="4" className="py-6 text-center text-slate-400">No snapshots saved yet.</td></tr>}
                            {history.map((h) => (
                                <tr key={h.id} className="border-b last:border-0 hover:bg-slate-200">
                                    <td className="py-2 pr-4">{h.date}</td>
                                    <td className="py-2 pr-4 text-right tabular-nums">{num(h.liquid_cash)}</td>
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

// A value cell (Amount / A/C Balance / Debt-Exp) is shown for a manual row, or
// for the one column an auto row represents. Everything else is left blank so
// the grid stays aligned under its headers.
const showVal = (r, col) => Boolean(r.manual) || r.auto_field === col;

function RowGroup({ group, rows, setField, removeRow, addRow, editable }) {
    const label = { top: 'Cash & Credit', banks: 'Bank Accounts', other: 'Expenses & Other' }[group];
    return (
        <>
            <tr className={groupTint[group]}>
                <td colSpan={7} className="px-3 pt-3 pb-1 text-[11px] font-bold uppercase tracking-wide text-slate-400">{label}</td>
            </tr>
            {rows.map((r, i) => (r.group === group ? (
                <tr key={r.key ?? i} className={'border-b border-slate-100 ' + groupTint[group]}>
                    <td className="px-3 py-1">
                        {r.manual ? (
                            <input className={input + ' text-left disabled:border-slate-200 disabled:bg-slate-50 disabled:text-navy-800'} placeholder="Row label" value={r.label ?? ''} disabled={!editable} onChange={(e) => setField(i, 'label', e.target.value)} />
                        ) : (
                            <span className="font-medium text-navy-800">{r.label}
                                {r.currency === 'OMR' && <span className="ml-2 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-500">OMR</span>}
                            </span>
                        )}
                    </td>
                    {/* Auto rows expose only their own field; manual rows keep all three.
                        Cash is counted in the Cash & Credit section only. */}
                    {showVal(r, 'amount') ? <NumCell value={r.amount} onChange={(v) => setField(i, 'amount', v)} editable={editable} /> : <EmptyCell />}
                    {showVal(r, 'ac_balance') ? <NumCell value={r.ac_balance} onChange={(v) => setField(i, 'ac_balance', v)} editable={editable} /> : <EmptyCell />}
                    {showVal(r, 'debt_exp') ? <NumCell value={r.debt_exp} onChange={(v) => setField(i, 'debt_exp', v)} editable={editable} /> : <EmptyCell />}
                    {r.group === 'top' ? <NumCell value={r.cash_aed} onChange={(v) => setField(i, 'cash_aed', v)} editable={editable} /> : <EmptyCell />}
                    {r.group === 'top' ? <NumCell value={r.cash_omr} onChange={(v) => setField(i, 'cash_omr', v)} editable={editable} /> : <EmptyCell />}
                    <td className="px-2 text-center">
                        {r.manual && <button type="button" onClick={() => removeRow(i)} disabled={!editable} className="text-accent-red hover:text-accent-red-dark disabled:cursor-not-allowed disabled:text-slate-300">✕</button>}
                    </td>
                </tr>
            ) : null))}
            <tr className={groupTint[group]}>
                <td colSpan={7} className="px-3 pb-2">
                    <button type="button" onClick={() => addRow(group)} disabled={!editable} className="text-xs font-semibold text-primary-600 hover:underline disabled:cursor-not-allowed disabled:text-slate-300 disabled:no-underline">+ Add row</button>
                </td>
            </tr>
        </>
    );
}

function NumCell({ value, onChange, editable }) {
    return (
        <td className="px-2 py-1">
            <input type="number" step="0.01" className={cell + ' disabled:border-slate-200 disabled:bg-slate-50 disabled:text-navy-800'} value={value ?? ''} disabled={!editable} onChange={(e) => onChange(e.target.value)} />
        </td>
    );
}

// A blank placeholder cell — keeps columns aligned where a field is hidden.
function EmptyCell() {
    return <td className="px-2 py-1" />;
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
