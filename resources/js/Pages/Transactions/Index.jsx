import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card } from '@/Components/ui/Card';
import { AED, fmtDate } from '@/lib/format';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

const input = 'rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';

export default function TransactionsIndex({ transactions, filters, customers, paymentMethods }) {
    const role = usePage().props.auth.user.role;
    const canWrite = ['super_admin', 'admin', 'accountant'].includes(role);
    const [f, setF] = useState(filters);

    const apply = (e) => {
        e?.preventDefault();
        router.get(route('transactions.index'), f, { preserveState: true, replace: true });
    };
    const reset = () => {
        setF({});
        router.get(route('transactions.index'));
    };
    const del = (id) => {
        if (confirm('Delete this transaction?')) router.delete(route('transactions.destroy', id));
    };

    return (
        <AuthenticatedLayout header="Transactions">
            <Head title="Transactions" />

            <div className="mb-4 flex items-center justify-between">
                <p className="text-sm text-slate-500">{transactions.total} transaction(s)</p>
                {canWrite && (
                    <Link href={route('transactions.create')} className="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-primary-700">
                        + New Transaction
                    </Link>
                )}
            </div>

            <Card className="mb-4">
                <form onSubmit={apply} className="grid grid-cols-2 gap-3 md:grid-cols-6">
                    <input className={input} placeholder="Search invoice/BOE/customer" value={f.search || ''} onChange={(e) => setF({ ...f, search: e.target.value })} />
                    <input type="date" className={input} value={f.from || ''} onChange={(e) => setF({ ...f, from: e.target.value })} />
                    <input type="date" className={input} value={f.to || ''} onChange={(e) => setF({ ...f, to: e.target.value })} />
                    <select className={input} value={f.customer_id || ''} onChange={(e) => setF({ ...f, customer_id: e.target.value })}>
                        <option value="">All customers</option>
                        {customers.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </select>
                    <select className={input} value={f.payment_method_id || ''} onChange={(e) => setF({ ...f, payment_method_id: e.target.value })}>
                        <option value="">All methods</option>
                        {paymentMethods.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                    </select>
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
                                <th className="py-2 pr-4">Date</th>
                                <th className="py-2 pr-4">Invoice</th>
                                <th className="py-2 pr-4">BOE</th>
                                <th className="py-2 pr-4">Customer</th>
                                <th className="py-2 pr-4">Vehicle</th>
                                <th className="py-2 pr-4">Method</th>
                                <th className="py-2 pr-4 text-right">Total</th>
                                <th className="py-2 pr-4 text-right">Grand Total</th>
                                <th className="py-2 pr-4 text-right">Net Profit</th>
                                {canWrite && <th className="py-2 pr-4"></th>}
                            </tr>
                        </thead>
                        <tbody>
                            {transactions.data.length === 0 && (
                                <tr><td colSpan="10" className="py-10 text-center text-slate-400">No transactions found.</td></tr>
                            )}
                            {transactions.data.map((t) => (
                                <tr key={t.id} className="border-b last:border-0 hover:bg-slate-50">
                                    <td className="py-2 pr-4 whitespace-nowrap">{fmtDate(t.transaction_date)}</td>
                                    <td className="py-2 pr-4">{t.invoice_no || '—'}</td>
                                    <td className="py-2 pr-4">{t.boe_no || '—'}</td>
                                    <td className="py-2 pr-4">{t.customer?.name}</td>
                                    <td className="py-2 pr-4">{t.vehicle?.number || '—'}</td>
                                    <td className="py-2 pr-4">{t.payment_method?.name}</td>
                                    <td className="py-2 pr-4 text-right">{AED(t.total_amount)}</td>
                                    <td className="py-2 pr-4 text-right font-semibold text-navy-800">{AED(t.grand_total)}</td>
                                    <td className="py-2 pr-4 text-right text-emerald-700">{AED(t.net_profit)}</td>
                                    {canWrite && (
                                        <td className="py-2 pr-4 whitespace-nowrap text-right">
                                            <Link href={route('transactions.edit', t.id)} className="text-primary-600 hover:underline">Edit</Link>
                                            <button onClick={() => del(t.id)} className="ml-3 text-accent-red hover:underline">Delete</button>
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {transactions.links && transactions.last_page > 1 && (
                    <div className="mt-4 flex flex-wrap gap-1">
                        {transactions.links.map((l, i) => (
                            <Link
                                key={i}
                                href={l.url || '#'}
                                className={'rounded px-3 py-1 text-sm ' + (l.active ? 'bg-primary-600 text-white' : l.url ? 'text-slate-600 hover:bg-slate-100' : 'text-slate-300')}
                                dangerouslySetInnerHTML={{ __html: l.label }}
                            />
                        ))}
                    </div>
                )}
            </Card>
        </AuthenticatedLayout>
    );
}
