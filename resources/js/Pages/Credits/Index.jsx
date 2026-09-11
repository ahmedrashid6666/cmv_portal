import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card } from '@/Components/ui/Card';
import { AED, fmtDate } from '@/lib/format';
import { todayLocalISO } from '@/lib/date';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const input = 'w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';

export default function CreditsIndex({ outstanding, filters = {}, paymentMethods, banks = [], companyBanks = [] }) {
    const role = usePage().props.auth.user.role;
    const canWrite = ['super_admin', 'admin', 'accountant'].includes(role);
    const [payFor, setPayFor] = useState(null);
    const [search, setSearch] = useState(filters.search || '');
    const [statementFor, setStatementFor] = useState(null); // { customer_id, customer }
    const [statementBankId, setStatementBankId] = useState(companyBanks.find((b) => b.is_default)?.id ?? companyBanks[0]?.id ?? '');
    const [statementDate, setStatementDate] = useState(todayLocalISO());
    const [statementInvoiceNo, setStatementInvoiceNo] = useState('');
    const [statementPaymentMode, setStatementPaymentMode] = useState('');
    const { data, setData, post, processing, errors, reset } = useForm({
        transaction_id: null,
        payment_date: todayLocalISO(),
        amount: '',
        payment_method_id: '',
        bank_id: '',
        note: '',
    });

    const selectedMethod = paymentMethods.find((m) => String(m.id) === String(data.payment_method_id));
    const isBankMethod = selectedMethod?.type === 'bank';
    const setPaymentMethod = (id) => {
        const method = paymentMethods.find((m) => String(m.id) === String(id));
        setData({ ...data, payment_method_id: id, bank_id: method?.type === 'bank' ? data.bank_id : '' });
    };

    const open = (row) => { setPayFor(row); setData({ ...data, transaction_id: row.id, amount: row.outstanding }); };
    const submit = (e) => {
        e.preventDefault();
        post(route('credits.store'), { onSuccess: () => { reset(); setPayFor(null); } });
    };

    const runSearch = (e) => {
        e?.preventDefault();
        router.get(route('credits.index'), { search }, { preserveState: true, replace: true });
    };

    const openStatement = (row) => setStatementFor(row);
    // Always include bank_id, even empty — that's how the backend tells "no bank
    // section, deliberately chosen" apart from "not specified, use the default".
    const statementUrl = statementFor
        ? route('credits.statement', { customer: statementFor.customer_id, bank_id: statementBankId, date: statementDate, invoice_no: statementInvoiceNo, payment_mode: statementPaymentMode })
        : '#';

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

            <Card className="mb-4">
                <form onSubmit={runSearch} className="flex flex-wrap items-end gap-2">
                    <label className="block flex-1">
                        <span className="mb-1 block text-[11px] font-medium text-slate-500">Search by Reference, Customer or Invoice</span>
                        <input className={input + ' max-w-md'} placeholder="e.g. JRY or ESQUBE" value={search} onChange={(e) => setSearch(e.target.value)} />
                    </label>
                    <button className="rounded-lg bg-navy-700 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800">Search</button>
                    {filters.search && (
                        <button type="button" onClick={() => { setSearch(''); router.get(route('credits.index')); }} className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50">Reset</button>
                    )}
                </form>
            </Card>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <Card title="Credit Sales — Outstanding" className="lg:col-span-2">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left text-xs uppercase text-slate-500">
                                    <th className="py-2 pr-4">Date</th>
                                    <th className="py-2 pr-4">Invoice</th>
                                    <th className="py-2 pr-4">Customer</th>
                                    <th className="py-2 pr-4">Reference</th>
                                    <th className="py-2 pr-4 text-right">Credit</th>
                                    <th className="py-2 pr-4 text-right">Outstanding</th>
                                    <th></th>
                                    {canWrite && <th></th>}
                                </tr>
                            </thead>
                            <tbody>
                                {outstanding.length === 0 && <tr><td colSpan="8" className="py-8 text-center text-slate-400">No outstanding credit. 🎉</td></tr>}
                                {outstanding.map((r) => (
                                    <tr key={r.id} className="border-b last:border-0 hover:bg-slate-200">
                                        <td className="py-2 pr-4">{fmtDate(r.date)}</td>
                                        <td className="py-2 pr-4">{r.invoice_no || '—'}</td>
                                        <td className="py-2 pr-4">{r.customer}</td>
                                        <td className="py-2 pr-4">{r.reference || '—'}</td>
                                        <td className="py-2 pr-4 text-right">{AED(r.credit_amount)}</td>
                                        <td className="py-2 pr-4 text-right font-semibold text-accent-red">{AED(r.outstanding)}</td>
                                        <td className="py-2 pr-4 text-right whitespace-nowrap">
                                            <button onClick={() => openStatement(r)} className="text-navy-600 hover:underline">Statement</button>
                                        </td>
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
                                <select className={input} value={data.payment_method_id} onChange={(e) => setPaymentMethod(e.target.value)}>
                                    <option value="">Select…</option>
                                    {paymentMethods.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                                </select>
                                {errors.payment_method_id && <span className="mt-1 block text-xs text-accent-red">{errors.payment_method_id}</span>}
                            </label>
                            {isBankMethod && (
                                <label className="block">
                                    <span className="mb-1 block text-xs font-medium text-slate-600">Bank Account</span>
                                    <select className={input} value={data.bank_id} onChange={(e) => setData('bank_id', e.target.value)}>
                                        <option value="">Select bank…</option>
                                        {banks.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                                    </select>
                                    {errors.bank_id && <span className="mt-1 block text-xs text-accent-red">{errors.bank_id}</span>}
                                </label>
                            )}
                            <button disabled={processing} className="w-full rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-primary-700 disabled:opacity-50">Record Payment</button>
                        </form>
                    </Card>
                )}
            </div>

            {statementFor && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                    <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                        <h3 className="text-lg font-semibold text-navy-800">Download Outstanding Statement</h3>
                        <p className="mt-1 text-sm text-slate-500">{statementFor.customer}</p>

                        <label className="mt-4 block">
                            <span className="mb-1 block text-xs font-medium text-slate-600">Date</span>
                            <input type="date" className={input} value={statementDate} onChange={(e) => setStatementDate(e.target.value)} />
                        </label>

                        <label className="mt-3 block">
                            <span className="mb-1 block text-xs font-medium text-slate-600">Invoice No (optional)</span>
                            <input className={input} value={statementInvoiceNo} onChange={(e) => setStatementInvoiceNo(e.target.value)} />
                        </label>

                        <label className="mt-3 block">
                            <span className="mb-1 block text-xs font-medium text-slate-600">Mode of Payment</span>
                            <select className={input} value={statementPaymentMode} onChange={(e) => setStatementPaymentMode(e.target.value)}>
                                <option value="">—</option>
                                <option value="Cash">Cash</option>
                                <option value="Account">Account</option>
                            </select>
                        </label>

                        <label className="mt-3 block">
                            <span className="mb-1 block text-xs font-medium text-slate-600">Show bank details for</span>
                            <select className={input} value={statementBankId} onChange={(e) => setStatementBankId(e.target.value)}>
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
