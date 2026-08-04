import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card } from '@/Components/ui/Card';
import { money } from '@/lib/format';
import { fmtDate } from '@/lib/format';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

const input = 'rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';

export default function BankStatement({ statement, filters }) {
    const [f, setF] = useState({ from: filters.from || '', to: filters.to || '' });
    const apply = (e) => { e?.preventDefault(); router.get(route('bank-accounts.statement', statement.bank.id), f, { preserveState: true, replace: true }); };
    const reset = () => router.get(route('bank-accounts.statement', statement.bank.id));
    const exportUrl = (format) => route('bank-accounts.statement.export', { bank: statement.bank.id, ...f, format });

    return (
        <AuthenticatedLayout header={`Bank Statement — ${statement.bank.name}`}>
            <Head title={`Statement — ${statement.bank.name}`} />

            <div className="mb-4 flex items-center justify-between gap-2">
                <Link href={route('bank-accounts.index')} className="text-sm font-semibold text-primary-600 hover:underline">← All bank accounts</Link>
                <div className="flex gap-2">
                    <a href={exportUrl('pdf')} target="_blank" className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-navy-700 hover:bg-slate-50">
                        Print / PDF
                    </a>
                    <a href={exportUrl('xlsx')} className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-navy-700 hover:bg-slate-50">
                        Export Excel
                    </a>
                </div>
            </div>

            <Card className="mb-4">
                <form onSubmit={apply} className="flex flex-wrap items-end gap-2">
                    <label className="block">
                        <span className="mb-1 block text-[11px] font-medium text-slate-500">From</span>
                        <input type="date" className={input} value={f.from} onChange={(e) => setF({ ...f, from: e.target.value })} />
                    </label>
                    <label className="block">
                        <span className="mb-1 block text-[11px] font-medium text-slate-500">To</span>
                        <input type="date" className={input} value={f.to} onChange={(e) => setF({ ...f, to: e.target.value })} />
                    </label>
                    <button className="rounded-lg bg-navy-700 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800">Filter</button>
                    <button type="button" onClick={reset} className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50">Reset</button>
                </form>
            </Card>

            <Card>
                <div className="mb-3 flex flex-wrap items-center justify-between gap-2 text-sm">
                    <span className="text-slate-500">Opening Balance: <span className="font-semibold text-navy-900">{money(statement.opening, 'AED')}</span></span>
                    {statement.total_in > 0 && <span className="text-slate-500">Total In: <span className="font-semibold text-emerald-700">{money(statement.total_in, 'AED')}</span></span>}
                    <span className="text-slate-500">Total Out: <span className="font-semibold text-accent-red">{money(statement.total_out, 'AED')}</span></span>
                    <span className="text-slate-500">Closing Balance: <span className={'font-semibold ' + (statement.closing < 0 ? 'text-accent-red' : 'text-emerald-700')}>{money(statement.closing, 'AED')}</span></span>
                </div>
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left text-xs uppercase text-slate-500">
                                <th className="py-2 pr-4">Date</th>
                                <th className="py-2 pr-4">Description</th>
                                <th className="py-2 pr-4">Invoice</th>
                                <th className="py-2 pr-4 text-right">In</th>
                                <th className="py-2 pr-4 text-right">Out</th>
                                <th className="py-2 pr-4 text-right">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr className="border-b bg-slate-50 text-slate-500">
                                <td className="py-2 pr-4" colSpan="5">Opening balance</td>
                                <td className="py-2 pr-4 text-right font-semibold">{money(statement.opening, 'AED')}</td>
                            </tr>
                            {statement.rows.length === 0 && <tr><td colSpan="6" className="py-8 text-center text-slate-400">No activity in this period.</td></tr>}
                            {statement.rows.map((r, i) => (
                                <tr key={i} className="border-b last:border-0 hover:bg-slate-200">
                                    <td className="py-2 pr-4 whitespace-nowrap">{fmtDate(r.date)}</td>
                                    <td className="py-2 pr-4">{r.description}</td>
                                    <td className="py-2 pr-4 text-slate-500">{r.ref || '—'}</td>
                                    <td className="py-2 pr-4 text-right tabular-nums text-emerald-700">{r.debit > 0 ? money(r.debit, 'AED') : '—'}</td>
                                    <td className="py-2 pr-4 text-right tabular-nums text-accent-red">{r.credit > 0 ? '−' + money(r.credit, 'AED') : '—'}</td>
                                    <td className="py-2 pr-4 text-right tabular-nums font-medium">{money(r.balance, 'AED')}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </Card>
        </AuthenticatedLayout>
    );
}
