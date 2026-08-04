import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card } from '@/Components/ui/Card';
import { AED } from '@/lib/format';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { Bar, BarChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

const input = 'rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';

export default function ReportShow({ report, filters, customers }) {
    const [f, setF] = useState(filters);
    const type = report.type;

    const apply = (e) => {
        e?.preventDefault();
        router.get(route('reports.show', type), f, { preserveState: true, replace: true });
    };
    const exportUrl = (format) => {
        const params = new URLSearchParams({ ...f, format }).toString();
        window.open(route('reports.export', type) + '?' + params, '_blank');
    };

    return (
        <AuthenticatedLayout header={report.title}>
            <Head title={report.title} />

            <Card className="mb-4">
                <form onSubmit={apply} className="flex flex-wrap items-end gap-3">
                    {type === 'daily' && (
                        <Labeled label="Date">
                            <input type="date" className={input} value={f.date || ''} onChange={(e) => setF({ ...f, date: e.target.value })} />
                        </Labeled>
                    )}
                    {type === 'monthly' && (
                        <>
                            <Labeled label="Year">
                                <input type="number" className={input} value={f.year || ''} onChange={(e) => setF({ ...f, year: e.target.value })} placeholder="2026" />
                            </Labeled>
                            <Labeled label="Month">
                                <input type="number" min="1" max="12" className={input} value={f.month || ''} onChange={(e) => setF({ ...f, month: e.target.value })} placeholder="7" />
                            </Labeled>
                        </>
                    )}
                    {type === 'yearly' && (
                        <Labeled label="Year">
                            <input type="number" className={input} value={f.year || ''} onChange={(e) => setF({ ...f, year: e.target.value })} placeholder="2026" />
                        </Labeled>
                    )}
                    {['customer', 'weekly', 'custom', 'vehicle', 'reference', 'payment-method', 'commission', 'expense', 'income', 'profit'].includes(type) && (
                        <>
                            <Labeled label="From"><input type="date" className={input} value={f.from || ''} onChange={(e) => setF({ ...f, from: e.target.value })} /></Labeled>
                            <Labeled label="To"><input type="date" className={input} value={f.to || ''} onChange={(e) => setF({ ...f, to: e.target.value })} /></Labeled>
                            {type === 'customer' && (
                                <Labeled label="Customer">
                                    <select className={input} value={f.customer_id || ''} onChange={(e) => setF({ ...f, customer_id: e.target.value })}>
                                        <option value="">All</option>
                                        {customers.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                                    </select>
                                </Labeled>
                            )}
                        </>
                    )}
                    <button className="rounded-lg bg-navy-700 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800">Apply</button>
                    <div className="ml-auto flex gap-2">
                        <button type="button" onClick={() => exportUrl('xlsx')} className="rounded-lg border border-emerald-600 px-3 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-50">Excel</button>
                        <button type="button" onClick={() => exportUrl('pdf')} className="rounded-lg border border-accent-red px-3 py-2 text-sm font-semibold text-accent-red hover:bg-red-50">PDF</button>
                        <button type="button" onClick={() => window.print()} className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">Print</button>
                    </div>
                </form>
            </Card>

            <div className="mb-4 grid grid-cols-2 gap-4 md:grid-cols-4">
                {Object.entries(report.totals).map(([label, value]) => (
                    <div key={label} className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs uppercase text-slate-500">{label}</p>
                        <p className="mt-1 text-xl font-bold text-navy-900">{AED(value)}</p>
                    </div>
                ))}
            </div>

            {report.chart.length > 0 && (
                <Card title="Chart" className="mb-4">
                    <ResponsiveContainer width="100%" height={240}>
                        <BarChart data={report.chart}>
                            <XAxis dataKey="label" fontSize={11} />
                            <YAxis fontSize={11} />
                            <Tooltip formatter={(v) => AED(v)} />
                            <Bar dataKey="income" name="Income" fill="#1b9a9b" radius={[4, 4, 0, 0]} />
                            {report.chart[0]?.profit !== undefined && <Bar dataKey="profit" name="Profit" fill="#1e3a5f" radius={[4, 4, 0, 0]} />}
                        </BarChart>
                    </ResponsiveContainer>
                </Card>
            )}

            <Card title={report.title}>
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left text-xs uppercase text-slate-500">
                                {report.columns.map((c) => <th key={c} className="py-2 pr-4">{c}</th>)}
                            </tr>
                        </thead>
                        <tbody>
                            {report.rows.length === 0 && <tr><td colSpan={report.columns.length} className="py-8 text-center text-slate-400">No data for this period.</td></tr>}
                            {report.rows.map((row, i) => (
                                <tr key={i} className="border-b last:border-0 hover:bg-slate-100">
                                    {row.map((cell, j) => <td key={j} className={'py-2 pr-4 ' + (j >= 3 ? 'text-right tabular-nums' : '')}>{cell}</td>)}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </Card>
        </AuthenticatedLayout>
    );
}

function Labeled({ label, children }) {
    return (
        <label className="block">
            <span className="mb-1 block text-xs font-medium text-slate-600">{label}</span>
            {children}
        </label>
    );
}
