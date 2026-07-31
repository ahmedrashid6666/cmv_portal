import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card } from '@/Components/ui/Card';
import { money, num } from '@/lib/format';
import { Head, router, useForm } from '@inertiajs/react';
import { useMemo } from 'react';

const input = 'w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';

export default function CashCount({ date, denominations, count, expectedAed, history }) {
    const { data, setData, post, processing } = useForm({
        count_date: date,
        lines: count?.lines ?? { AED: {}, OMR: {} },
        extras: count?.extras ?? { AED: [], OMR: [] },
        remarks: count?.remarks ?? '',
    });

    const setQty = (cur, denom, qty) => setData('lines', { ...data.lines, [cur]: { ...data.lines[cur], [denom]: qty } });
    const setExtra = (cur, i, k, v) => setData('extras', { ...data.extras, [cur]: data.extras[cur].map((r, idx) => (idx === i ? { ...r, [k]: v } : r)) });
    const addExtra = (cur) => setData('extras', { ...data.extras, [cur]: [...data.extras[cur], { label: '', amount: '' }] });
    const removeExtra = (cur, i) => setData('extras', { ...data.extras, [cur]: data.extras[cur].filter((_, idx) => idx !== i) });

    const totals = useMemo(() => {
        const t = {};
        for (const cur of Object.keys(denominations)) {
            let sum = 0;
            for (const d of denominations[cur]) sum += d * (parseFloat(data.lines[cur]?.[d]) || 0);
            for (const r of data.extras[cur] || []) sum += parseFloat(r.amount) || 0;
            t[cur] = cur === 'OMR' ? Math.round(sum * 1000) / 1000 : Math.round(sum * 100) / 100;
        }
        return t;
    }, [data.lines, data.extras, denominations]);

    const variance = Math.round((totals.AED - expectedAed) * 100) / 100;

    const changeDate = (d) => router.get(route('cash-count.index'), { date: d }, { preserveState: false });
    const save = (e) => { e.preventDefault(); post(route('cash-count.store'), { preserveScroll: true }); };

    return (
        <AuthenticatedLayout header="Daily Cash Count">
            <Head title="Daily Cash Count" />

            <div className="mb-4 flex flex-wrap items-end gap-3">
                <label className="block">
                    <span className="mb-1 block text-xs font-medium text-slate-600">Count Date</span>
                    <input type="date" className={input + ' w-44'} value={data.count_date} onChange={(e) => { setData('count_date', e.target.value); changeDate(e.target.value); }} />
                </label>
                <button onClick={save} disabled={processing} className="rounded-lg bg-primary-600 px-5 py-2 text-sm font-semibold text-white shadow hover:bg-primary-700 disabled:opacity-50">
                    Save Count
                </button>
            </div>

            {/* Reconciliation banner (AED) */}
            <div className="mb-4 grid grid-cols-1 gap-4 md:grid-cols-3">
                <Stat label="Counted (AED)" value={money(totals.AED, 'AED')} accent="text-navy-900" />
                <Stat label="Expected Cash (AED)" value={money(expectedAed, 'AED')} accent="text-navy-900" />
                <div className={'rounded-xl border p-4 shadow-sm ' + (variance === 0 ? 'border-emerald-200 bg-emerald-50' : variance > 0 ? 'border-amber-200 bg-amber-50' : 'border-red-200 bg-red-50')}>
                    <p className="text-xs uppercase text-slate-500">Difference</p>
                    <p className={'mt-1 text-2xl font-bold ' + (variance === 0 ? 'text-emerald-700' : variance > 0 ? 'text-amber-600' : 'text-accent-red')}>
                        {variance === 0 ? 'Balanced ✓' : `${variance > 0 ? 'Over' : 'Short'} ${money(Math.abs(variance), 'AED')}`}
                    </p>
                </div>
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                {Object.keys(denominations).map((cur) => (
                    <Card key={cur} title={`${cur} Cash Count`} action={<span className="text-sm font-bold text-primary-700">{money(totals[cur], cur)}</span>}>
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left text-xs uppercase text-slate-500">
                                    <th className="py-1.5 pr-3">Denomination</th>
                                    <th className="py-1.5 pr-3 text-center">Qty</th>
                                    <th className="py-1.5 text-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                {denominations[cur].map((d) => {
                                    const qty = parseFloat(data.lines[cur]?.[d]) || 0;
                                    return (
                                        <tr key={d} className="border-b last:border-0">
                                            <td className="py-1 pr-3 font-medium text-navy-800">{num(d)}</td>
                                            <td className="py-1 pr-3">
                                                <input type="number" min="0" step="1" className={input + ' text-center'} value={data.lines[cur]?.[d] ?? ''} onChange={(e) => setQty(cur, d, e.target.value)} />
                                            </td>
                                            <td className="py-1 text-right tabular-nums text-slate-600">{qty ? num(d * qty) : '—'}</td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>

                        {/* Bundles / slips - IN/OUT table */}
                        <div className="mt-4 border-t pt-4">
                            <div className="mb-3 flex items-center justify-between">
                                <span className="text-xs font-semibold uppercase text-slate-600">Bundles / Slips</span>
                            </div>
                            <div className="overflow-x-auto">
                                <table className="w-full text-xs border-collapse">
                                    <thead>
                                        <tr className="bg-slate-100">
                                            <th colSpan="2" className="border border-slate-400 py-2 text-center font-bold text-navy-800">IN</th>
                                            <th colSpan="2" className="border border-slate-400 py-2 text-center font-bold text-navy-800">OUT</th>
                                        </tr>
                                        <tr className="bg-slate-50">
                                            <th className="border border-slate-400 py-1.5 px-2 text-left text-slate-600">Details</th>
                                            <th className="border border-slate-400 py-1.5 px-2 text-right text-slate-600">Amount</th>
                                            <th className="border border-slate-400 py-1.5 px-2 text-left text-slate-600">Details</th>
                                            <th className="border border-slate-400 py-1.5 px-2 text-right text-slate-600">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {(() => {
                                            const extras = data.extras[cur] || [];
                                            const inItems = extras.filter((_, i) => i % 2 === 0);
                                            const outItems = extras.filter((_, i) => i % 2 === 1);
                                            const maxRows = Math.max(inItems.length + 1, outItems.length + 1, 5);

                                            return Array.from({ length: maxRows }).map((_, row) => (
                                                <tr key={row}>
                                                    <td className="border border-slate-300 py-2 px-2">
                                                        <input
                                                            type="text"
                                                            className={input + ' text-xs'}
                                                            placeholder="Details"
                                                            value={inItems[row]?.label || ''}
                                                            onChange={(e) => setExtra(cur, row * 2, 'label', e.target.value)}
                                                        />
                                                    </td>
                                                    <td className="border border-slate-300 py-2 px-2">
                                                        <input
                                                            type="number"
                                                            step="0.01"
                                                            className={input + ' text-xs text-right'}
                                                            placeholder="0.00"
                                                            value={inItems[row]?.amount || ''}
                                                            onChange={(e) => setExtra(cur, row * 2, 'amount', e.target.value)}
                                                        />
                                                    </td>
                                                    <td className="border border-slate-300 py-2 px-2">
                                                        <input
                                                            type="text"
                                                            className={input + ' text-xs'}
                                                            placeholder="Details"
                                                            value={outItems[row]?.label || ''}
                                                            onChange={(e) => setExtra(cur, row * 2 + 1, 'label', e.target.value)}
                                                        />
                                                    </td>
                                                    <td className="border border-slate-300 py-2 px-2">
                                                        <input
                                                            type="number"
                                                            step="0.01"
                                                            className={input + ' text-xs text-right'}
                                                            placeholder="0.00"
                                                            value={outItems[row]?.amount || ''}
                                                            onChange={(e) => setExtra(cur, row * 2 + 1, 'amount', e.target.value)}
                                                        />
                                                    </td>
                                                </tr>
                                            ));
                                        })()}
                                        <tr className="border-t-2 border-navy-800 bg-slate-100 font-bold">
                                            <td className="border border-slate-400 py-2 px-2 text-right">Total</td>
                                            <td className="border border-slate-400 py-2 px-2 text-right tabular-nums">
                                                {(() => {
                                                    const inTotal = (data.extras[cur] || [])
                                                        .filter((_, i) => i % 2 === 0)
                                                        .reduce((sum, r) => sum + (parseFloat(r.amount) || 0), 0);
                                                    return num(inTotal);
                                                })()}
                                            </td>
                                            <td className="border border-slate-400 py-2 px-2 text-right">Total</td>
                                            <td className="border border-slate-400 py-2 px-2 text-right tabular-nums">
                                                {(() => {
                                                    const outTotal = (data.extras[cur] || [])
                                                        .filter((_, i) => i % 2 === 1)
                                                        .reduce((sum, r) => sum + (parseFloat(r.amount) || 0), 0);
                                                    return num(outTotal);
                                                })()}
                                            </td>
                                        </tr>
                                        <tr className="font-bold text-navy-800">
                                            <td colSpan="2" className="border border-slate-400 py-2 px-2">Balance Amount</td>
                                            <td colSpan="2" className="border border-slate-400 py-2 px-2 text-right tabular-nums">
                                                {(() => {
                                                    const inTotal = (data.extras[cur] || [])
                                                        .filter((_, i) => i % 2 === 0)
                                                        .reduce((sum, r) => sum + (parseFloat(r.amount) || 0), 0);
                                                    const outTotal = (data.extras[cur] || [])
                                                        .filter((_, i) => i % 2 === 1)
                                                        .reduce((sum, r) => sum + (parseFloat(r.amount) || 0), 0);
                                                    const balance = inTotal - outTotal;
                                                    return num(balance);
                                                })()}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div className="mt-3 flex justify-end">
                                <button type="button" onClick={() => addExtra(cur)} className="text-xs font-semibold text-primary-600 hover:underline">+ Add Row</button>
                            </div>
                        </div>
                    </Card>
                ))}
            </div>

            <Card className="mt-4">
                <label className="block">
                    <span className="mb-1 block text-xs font-medium text-slate-600">Remarks</span>
                    <textarea rows="2" className={input} value={data.remarks} onChange={(e) => setData('remarks', e.target.value)} />
                </label>
            </Card>

            {/* History */}
            <Card title="Recent Counts" className="mt-4">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left text-xs uppercase text-slate-500">
                                <th className="py-2 pr-4">Date</th>
                                <th className="py-2 pr-4 text-right">AED</th>
                                <th className="py-2 pr-4 text-right">OMR</th>
                                <th className="py-2 pr-4 text-right">Difference</th>
                                <th className="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {history.length === 0 && <tr><td colSpan="5" className="py-6 text-center text-slate-400">No counts saved yet.</td></tr>}
                            {history.map((h) => (
                                <tr key={h.id} className="border-b last:border-0 hover:bg-slate-50">
                                    <td className="py-2 pr-4">{h.date}</td>
                                    <td className="py-2 pr-4 text-right">{money(h.total_aed, 'AED')}</td>
                                    <td className="py-2 pr-4 text-right">{money(h.total_omr, 'OMR')}</td>
                                    <td className={'py-2 pr-4 text-right font-semibold ' + (h.variance === 0 ? 'text-emerald-700' : h.variance > 0 ? 'text-amber-600' : 'text-accent-red')}>
                                        {h.variance === 0 ? 'Balanced' : `${h.variance > 0 ? '+' : ''}${num(h.variance)}`}
                                    </td>
                                    <td className="py-2 text-right">
                                        <a href={route('cash-count.pdf', h.id)} target="_blank" className="text-primary-600 hover:underline">PDF</a>
                                        <button onClick={() => changeDate(h.date)} className="ml-3 text-navy-600 hover:underline">Open</button>
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

function Stat({ label, value, accent }) {
    return (
        <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <p className="text-xs uppercase text-slate-500">{label}</p>
            <p className={'mt-1 text-2xl font-bold ' + accent}>{value}</p>
        </div>
    );
}
