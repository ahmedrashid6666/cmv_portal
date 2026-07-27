import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card } from '@/Components/ui/Card';
import { AED, fmtDate } from '@/lib/format';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const input = 'w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';

export default function CreditsIndex({ outstanding, paymentMethods }) {
    const role = usePage().props.auth.user.role;
    const canWrite = ['super_admin', 'admin', 'accountant'].includes(role);
    const [payFor, setPayFor] = useState(null);
    const { data, setData, post, processing, errors, reset } = useForm({
        transaction_id: null,
        payment_date: new Date().toISOString().slice(0, 10),
        amount: '',
        payment_method_id: '',
        note: '',
    });

    const open = (row) => { setPayFor(row); setData({ ...data, transaction_id: row.id, amount: row.outstanding }); };
    const submit = (e) => {
        e.preventDefault();
        post(route('credits.store'), { onSuccess: () => { reset(); setPayFor(null); } });
    };

    const total = outstanding.reduce((s, r) => s + Number(r.outstanding), 0);

    return (
        <AuthenticatedLayout header="Outstanding Credits">
            <Head title="Credits" />

            <div className="mb-4 grid grid-cols-2 gap-4 md:grid-cols-4">
                <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p className="text-xs uppercase text-slate-500">Total Outstanding</p>
                    <p className="mt-1 text-2xl font-bold text-accent-red">{AED(total)}</p>
                </div>
                <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p className="text-xs uppercase text-slate-500">Pending Invoices</p>
                    <p className="mt-1 text-2xl font-bold text-navy-900">{outstanding.length}</p>
                </div>
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <Card title="Credit Sales — Outstanding" className="lg:col-span-2">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left text-xs uppercase text-slate-500">
                                    <th className="py-2 pr-4">Date</th>
                                    <th className="py-2 pr-4">Invoice</th>
                                    <th className="py-2 pr-4">Customer</th>
                                    <th className="py-2 pr-4 text-right">Credit</th>
                                    <th className="py-2 pr-4 text-right">Outstanding</th>
                                    {canWrite && <th></th>}
                                </tr>
                            </thead>
                            <tbody>
                                {outstanding.length === 0 && <tr><td colSpan="6" className="py-8 text-center text-slate-400">No outstanding credit. 🎉</td></tr>}
                                {outstanding.map((r) => (
                                    <tr key={r.id} className="border-b last:border-0 hover:bg-slate-50">
                                        <td className="py-2 pr-4">{fmtDate(r.date)}</td>
                                        <td className="py-2 pr-4">{r.invoice_no || '—'}</td>
                                        <td className="py-2 pr-4">{r.customer}</td>
                                        <td className="py-2 pr-4 text-right">{AED(r.credit_amount)}</td>
                                        <td className="py-2 pr-4 text-right font-semibold text-accent-red">{AED(r.outstanding)}</td>
                                        {canWrite && <td className="py-2 text-right"><button onClick={() => open(r)} className="text-primary-600 hover:underline">Receive</button></td>}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>

                {canWrite && payFor && (
                    <Card title={`Receive Payment — ${payFor.customer}`} action={<button onClick={() => setPayFor(null)} className="text-xs text-slate-500 hover:underline">Close</button>}>
                        <form onSubmit={submit} className="space-y-3">
                            <p className="text-sm text-slate-500">Outstanding: <span className="font-semibold text-accent-red">{AED(payFor.outstanding)}</span></p>
                            <label className="block">
                                <span className="mb-1 block text-xs font-medium text-slate-600">Payment Date</span>
                                <input type="date" className={input} value={data.payment_date} onChange={(e) => setData('payment_date', e.target.value)} />
                            </label>
                            <label className="block">
                                <span className="mb-1 block text-xs font-medium text-slate-600">Amount</span>
                                <input type="number" step="0.01" className={input} value={data.amount} onChange={(e) => setData('amount', e.target.value)} />
                                {errors.amount && <span className="mt-1 block text-xs text-accent-red">{errors.amount}</span>}
                            </label>
                            <label className="block">
                                <span className="mb-1 block text-xs font-medium text-slate-600">Received In</span>
                                <select className={input} value={data.payment_method_id} onChange={(e) => setData('payment_method_id', e.target.value)}>
                                    <option value="">Select…</option>
                                    {paymentMethods.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                                </select>
                                {errors.payment_method_id && <span className="mt-1 block text-xs text-accent-red">{errors.payment_method_id}</span>}
                            </label>
                            <button disabled={processing} className="w-full rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-primary-700 disabled:opacity-50">Record Payment</button>
                        </form>
                    </Card>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
