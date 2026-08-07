import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card } from '@/Components/ui/Card';
import { money, num } from '@/lib/format';
import focusNextFieldOnEnter from '@/lib/focusNextFieldOnEnter';
import { Head, router, useForm, usePage } from '@inertiajs/react';

const input = 'w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';

export default function CashCount({ date, denominations, count, history }) {
    const role = usePage().props.auth.user.role;
    const canWrite = ['super_admin', 'admin', 'accountant'].includes(role);

    const { data, setData, post, processing } = useForm({
        count_date: date,
        // The AED/OMR denomination + bundle counts are edited on the Final
        // Calculation page now — this form still carries them so saving here
        // doesn't wipe out what was entered there.
        lines: count?.lines ?? { AED: {}, OMR: {} },
        bundles: count?.bundles ?? { AED: [], OMR: [] },
        extras: count?.extras ?? { AED: [], OMR: [] },
        remarks: count?.remarks ?? '',
    });

    const updateExtra = (cur, idx, key, value) => {
        const extras = [...(data.extras[cur] || [])];
        while (extras.length <= idx) extras.push({ label: '', amount: '' });
        extras[idx][key] = value;
        setData('extras', { ...data.extras, [cur]: extras });
    };

    const addExtra = (cur) => setData('extras', { ...data.extras, [cur]: [...(data.extras[cur] || []), { label: '', amount: '' }] });

    const changeDate = (d) => router.get(route('cash-count.index'), { date: d }, { preserveState: false });
    const save = (e) => { e.preventDefault(); post(route('cash-count.store'), { preserveScroll: true }); };
    const deleteCount = (h) => {
        if (confirm(`Delete the cash count for ${h.date}?`)) {
            router.delete(route('cash-count.destroy', h.id), { preserveScroll: true });
        }
    };

    return (
        <AuthenticatedLayout header="Daily Cash Count">
            <Head title="Daily Cash Count" />

            <div onKeyDown={focusNextFieldOnEnter}>
            <div className="mb-4 flex flex-wrap items-end gap-3">
                <label className="block">
                    <span className="mb-1 block text-xs font-medium text-slate-600">Count Date</span>
                    <input type="date" className={input + ' w-44'} value={data.count_date} onChange={(e) => { setData('count_date', e.target.value); changeDate(e.target.value); }} />
                </label>
                <button onClick={save} disabled={processing} className="rounded-lg bg-primary-600 px-5 py-2 text-sm font-semibold text-white shadow hover:bg-primary-700 disabled:opacity-50">
                    Save Count
                </button>
                <p className="text-xs text-slate-500">
                    AED/OMR denomination counting now happens on the{' '}
                    <a href={route('final-calc.index')} className="text-primary-600 hover:underline">Final Calculation</a> page.
                </p>
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                {Object.keys(denominations).map((cur) => (
                    <Card key={cur} title={`${cur} Bundles / Slips`}>
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
                                        const maxRows = Math.max(Math.ceil(extras.length / 2), 5);

                                        return Array.from({ length: maxRows }).map((_, row) => {
                                            const inIdx = row * 2;
                                            const outIdx = row * 2 + 1;
                                            const inItem = extras[inIdx] || { label: '', amount: '' };
                                            const outItem = extras[outIdx] || { label: '', amount: '' };

                                            return (
                                                <tr key={row}>
                                                    <td className="border border-slate-300 py-2 px-2">
                                                        <input type="text" className={input + ' text-xs'} placeholder="Details" value={inItem.label} onChange={(e) => updateExtra(cur, inIdx, 'label', e.target.value)} />
                                                    </td>
                                                    <td className="border border-slate-300 py-2 px-2">
                                                        <input type="number" step="0.01" className={input + ' text-xs text-right'} placeholder="0.00" value={inItem.amount} onChange={(e) => updateExtra(cur, inIdx, 'amount', e.target.value)} />
                                                    </td>
                                                    <td className="border border-slate-300 py-2 px-2">
                                                        <input type="text" className={input + ' text-xs'} placeholder="Details" value={outItem.label} onChange={(e) => updateExtra(cur, outIdx, 'label', e.target.value)} />
                                                    </td>
                                                    <td className="border border-slate-300 py-2 px-2">
                                                        <input type="number" step="0.01" className={input + ' text-xs text-right'} placeholder="0.00" value={outItem.amount} onChange={(e) => updateExtra(cur, outIdx, 'amount', e.target.value)} />
                                                    </td>
                                                </tr>
                                            );
                                        });
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
                                                return num(inTotal - outTotal);
                                            })()}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div className="mt-3 flex justify-end">
                            <button type="button" onClick={() => addExtra(cur)} className="text-xs font-semibold text-primary-600 hover:underline">+ Add Row</button>
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
            </div>

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
                                <tr key={h.id} className="border-b last:border-0 hover:bg-slate-200">
                                    <td className="py-2 pr-4">{h.date}</td>
                                    <td className="py-2 pr-4 text-right">{money(h.total_aed, 'AED')}</td>
                                    <td className="py-2 pr-4 text-right">{money(h.total_omr, 'OMR')}</td>
                                    <td className={'py-2 pr-4 text-right font-semibold ' + (h.variance === 0 ? 'text-emerald-700' : h.variance > 0 ? 'text-amber-600' : 'text-accent-red')}>
                                        {h.variance === 0 ? 'Balanced' : `${h.variance > 0 ? '+' : ''}${num(h.variance)}`}
                                    </td>
                                    <td className="py-2 text-right whitespace-nowrap">
                                        <a href={route('cash-count.pdf', h.id)} target="_blank" className="text-primary-600 hover:underline">PDF</a>
                                        <button onClick={() => changeDate(h.date)} className="ml-3 text-navy-600 hover:underline">Edit</button>
                                        {canWrite && <button onClick={() => deleteCount(h)} className="ml-3 text-accent-red hover:underline">Delete</button>}
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
