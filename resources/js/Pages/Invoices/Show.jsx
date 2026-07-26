import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { AED } from '@/lib/format';
import { Head, Link } from '@inertiajs/react';

const statusStyle = {
    paid: 'bg-emerald-100 text-emerald-700',
    partial: 'bg-amber-100 text-amber-700',
    unpaid: 'bg-red-100 text-accent-red-dark',
};

export default function InvoiceShow({ invoice }) {
    return (
        <AuthenticatedLayout header={`Invoice ${invoice.invoice_no}`}>
            <Head title={`Invoice ${invoice.invoice_no}`} />

            <div className="mx-auto max-w-3xl">
                <div className="mb-4 flex items-center justify-between print:hidden">
                    <Link href={route('invoices.index')} className="text-sm text-slate-500 hover:underline">← Back to invoices</Link>
                    <div className="flex gap-2">
                        <a href={route('invoices.pdf', invoice.id)} target="_blank" className="rounded-lg bg-accent-red px-4 py-2 text-sm font-semibold text-white hover:bg-accent-red-dark">Download PDF</a>
                        <button onClick={() => window.print()} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">Print</button>
                    </div>
                </div>

                <div className="rounded-xl border border-slate-200 bg-white p-8 shadow-sm">
                    {/* header */}
                    <div className="flex items-start justify-between border-b-4 border-primary-500 pb-5">
                        <div className="flex items-start gap-3">
                            <img src="/logo.png" alt="CMV" className="h-16 w-16" />
                            <div>
                                <p className="text-2xl font-bold text-navy-800">{invoice.company.name}</p>
                                <p className="mt-1 whitespace-pre-line text-xs text-slate-500">{invoice.company.address}</p>
                                <p className="text-xs text-slate-500">
                                    {invoice.company.phone}{invoice.company.email ? ' · ' + invoice.company.email : ''}
                                    {invoice.company.trn ? ` · TRN: ${invoice.company.trn}` : ''}
                                </p>
                            </div>
                        </div>
                        <div className="text-right">
                            <p className="text-3xl font-bold text-primary-600">INVOICE</p>
                            <p className="mt-1 text-sm text-slate-500"># {invoice.invoice_no}</p>
                            <p className="text-sm text-slate-500">Date: {invoice.date}</p>
                            <span className={'mt-2 inline-block rounded-full px-3 py-1 text-xs font-bold uppercase ' + statusStyle[invoice.status]}>{invoice.status}</span>
                        </div>
                    </div>

                    {/* meta */}
                    <div className="grid grid-cols-2 gap-6 py-6 text-sm">
                        <div>
                            <p className="text-xs uppercase text-slate-400">Bill To</p>
                            <p className="font-semibold text-navy-800">{invoice.customer.name}</p>
                            {invoice.customer.contact && <p className="text-slate-500">{invoice.customer.contact}</p>}
                        </div>
                        <div className="text-sm text-slate-600">
                            <p className="text-xs uppercase text-slate-400">Details</p>
                            {invoice.boe_no && <p>BOE No: {invoice.boe_no}</p>}
                            {invoice.vehicle && <p>Vehicle: {invoice.vehicle}</p>}
                            {invoice.reference && <p>Reference: {invoice.reference}</p>}
                            <p>Payment: {invoice.payment_method}</p>
                        </div>
                    </div>

                    {/* line items */}
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-navy-800 text-left text-xs uppercase text-white">
                                <th className="rounded-l px-3 py-2">Description</th>
                                <th className="rounded-r px-3 py-2 text-right">Amount ({invoice.currency})</th>
                            </tr>
                        </thead>
                        <tbody>
                            {invoice.lines.map((l, i) => (
                                <tr key={i} className="border-b">
                                    <td className="px-3 py-2">{l.label}</td>
                                    <td className="px-3 py-2 text-right tabular-nums">{AED(l.amount)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>

                    {/* totals */}
                    <div className="mt-4 flex justify-end">
                        <div className="w-64 space-y-1 text-sm">
                            <Row label="Subtotal" value={invoice.subtotal} />
                            <Row label={`VAT (${invoice.vat_rate}%)`} value={invoice.vat_amount} />
                            <div className="flex justify-between border-t-2 border-navy-700 pt-2 text-lg font-bold text-navy-800">
                                <span>Total</span><span>{AED(invoice.total)}</span>
                            </div>
                            <Row label="Paid" value={invoice.paid} />
                            {invoice.outstanding > 0 && (
                                <div className="flex justify-between font-bold text-accent-red">
                                    <span>Amount Due</span><span>{AED(invoice.outstanding)}</span>
                                </div>
                            )}
                        </div>
                    </div>

                    <p className="mt-10 border-t pt-4 text-center text-xs text-slate-400">{invoice.footer}</p>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function Row({ label, value }) {
    return (
        <div className="flex justify-between text-slate-600">
            <span>{label}</span><span className="tabular-nums">{AED(value)}</span>
        </div>
    );
}
