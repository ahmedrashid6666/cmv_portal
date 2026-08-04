import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card } from '@/Components/ui/Card';
import { AED, fmtDate } from '@/lib/format';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

const input = 'rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';

const statusStyle = {
    paid: 'bg-emerald-100 text-emerald-800',
    partial: 'bg-amber-100 text-amber-800',
    unpaid: 'bg-red-100 text-accent-red-dark',
};

export default function InvoicesIndex({ invoices, filters, customers }) {
    const [f, setF] = useState(filters);
    const apply = (e) => { e?.preventDefault(); router.get(route('invoices.index'), f, { preserveState: true, replace: true }); };
    const reset = () => { setF({}); router.get(route('invoices.index')); };

    return (
        <AuthenticatedLayout header="Invoices">
            <Head title="Invoices" />

            <Card className="mb-4">
                <form onSubmit={apply} className="grid grid-cols-2 gap-3 md:grid-cols-5">
                    <input className={input} placeholder="Search invoice #" value={f.search || ''} onChange={(e) => setF({ ...f, search: e.target.value })} />
                    <select className={input} value={f.customer_id || ''} onChange={(e) => setF({ ...f, customer_id: e.target.value })}>
                        <option value="">All customers</option>
                        {customers.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </select>
                    <input type="date" className={input} value={f.from || ''} onChange={(e) => setF({ ...f, from: e.target.value })} />
                    <input type="date" className={input} value={f.to || ''} onChange={(e) => setF({ ...f, to: e.target.value })} />
                    <div className="flex gap-2">
                        <button className="flex-1 rounded-lg bg-navy-700 px-3 py-2 text-sm font-semibold text-white hover:bg-navy-800">Filter</button>
                        <button type="button" onClick={reset} className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50">Reset</button>
                    </div>
                </form>
            </Card>

            <Card>
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left text-xs uppercase text-slate-500">
                                <th className="py-2 pr-4">Invoice #</th>
                                <th className="py-2 pr-4">Date</th>
                                <th className="py-2 pr-4">Customer</th>
                                <th className="py-2 pr-4 text-right">Total</th>
                                <th className="py-2 pr-4 text-right">Due</th>
                                <th className="py-2 pr-4">Status</th>
                                <th className="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {invoices.data.length === 0 && <tr><td colSpan="7" className="py-8 text-center text-slate-400">No invoices found.</td></tr>}
                            {invoices.data.map((inv) => (
                                <tr key={inv.id} className="border-b last:border-0 hover:bg-slate-100">
                                    <td className="py-2 pr-4 font-medium text-navy-800">{inv.invoice_no || `TXN-${inv.id}`}</td>
                                    <td className="py-2 pr-4">{fmtDate(inv.date)}</td>
                                    <td className="py-2 pr-4">{inv.customer}</td>
                                    <td className="py-2 pr-4 text-right font-semibold">{AED(inv.grand_total)}</td>
                                    <td className="py-2 pr-4 text-right text-accent-red">{inv.outstanding > 0 ? AED(inv.outstanding) : '—'}</td>
                                    <td className="py-2 pr-4"><span className={'rounded-full px-2 py-0.5 text-xs font-semibold ' + statusStyle[inv.status]}>{inv.status}</span></td>
                                    <td className="py-2 whitespace-nowrap text-right">
                                        <Link href={route('invoices.show', inv.id)} className="font-semibold text-primary-600 hover:underline">View</Link>
                                        <a href={route('invoices.pdf', inv.id)} target="_blank" className="ml-3 text-navy-600 hover:underline">PDF</a>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {invoices.last_page > 1 && (
                    <div className="mt-4 flex flex-wrap gap-1">
                        {invoices.links.map((l, i) => (
                            <Link key={i} href={l.url || '#'} className={'rounded px-3 py-1 text-sm ' + (l.active ? 'bg-primary-600 text-white' : l.url ? 'text-slate-600 hover:bg-slate-100' : 'text-slate-300')} dangerouslySetInnerHTML={{ __html: l.label }} />
                        ))}
                    </div>
                )}
            </Card>
        </AuthenticatedLayout>
    );
}
