import { Card } from '@/Components/ui/Card';
import ComboBox from '@/Components/ComboBox';
import { money } from '@/lib/format';
import { computeTotals } from '@/lib/calc';
import { Link, useForm } from '@inertiajs/react';
import { useMemo } from 'react';

const input =
    'w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';

function Field({ label, error, children, required }) {
    return (
        <label className="block">
            <span className="mb-1 block text-xs font-medium text-slate-600">
                {label} {required && <span className="text-accent-red">*</span>}
            </span>
            {children}
            {error && <span className="mt-1 block text-xs text-accent-red">{error}</span>}
        </label>
    );
}

function Row({ label, value, strong, big, accent, currency = 'AED' }) {
    const color = accent === 'primary' ? 'text-primary-700' : accent === 'green' ? 'text-emerald-700' : 'text-navy-900';
    return (
        <div className="flex items-center justify-between">
            <dt className={'text-slate-500 ' + (big ? 'font-semibold' : '')}>{label}</dt>
            <dd className={(big ? 'text-lg font-bold ' : strong ? 'font-semibold ' : '') + color}>{money(value, currency)}</dd>
        </div>
    );
}

/**
 * The full New/Edit transaction form (income/expense). Reused by the
 * standalone Transactions page and the unified Add Entry page.
 */
export default function TransactionEntryForm({
    transaction,
    customers,
    references,
    vehicles,
    paymentMethods,
    expenseCategories,
    vatRate,
    customFields = [],
    onDone,
}) {
    const editing = !!transaction;
    const { data, setData, post, put, processing, errors, reset } = useForm({
        transaction_date: transaction?.transaction_date ?? new Date().toISOString().slice(0, 10),
        invoice_no: transaction?.invoice_no ?? '',
        boe_no: transaction?.boe_no ?? '',
        customer_id: transaction?.customer_id ?? '',
        reference_id: transaction?.reference_id ?? '',
        vehicle_id: transaction?.vehicle_id ?? '',
        customs_fees: transaction?.customs_fees ?? 0,
        gov_fees: transaction?.gov_fees ?? 0,
        profit: transaction?.profit ?? 0,
        vat_rate: transaction?.vat_rate ?? vatRate ?? 0,
        currency: transaction?.currency ?? 'AED',
        payment_method_id: transaction?.payment_method_id ?? '',
        credit_amount: transaction?.credit_amount ?? 0,
        remarks: transaction?.remarks ?? '',
        expenses: transaction?.expenses?.length
            ? transaction.expenses.map((e) => ({ expense_category_id: e.expense_category_id ?? '', description: e.description ?? '', amount: e.amount ?? '' }))
            : [{ expense_category_id: '', description: '', amount: '' }],
        commissions: transaction?.commissions?.length
            ? transaction.commissions.map((c) => ({ label: c.label ?? '', amount: c.amount ?? '', type: c.type ?? 'charged_to_customer', reference_id: c.reference_id ?? '' }))
            : [{ label: '', amount: '', type: 'charged_to_customer', reference_id: '' }],
        custom_data: transaction?.custom_data ?? {},
    });

    const setCustom = (key, value) => setData('custom_data', { ...data.custom_data, [key]: value });
    const totals = useMemo(() => computeTotals(data), [data]);

    const setExpense = (i, k, v) => setData('expenses', data.expenses.map((r, idx) => (idx === i ? { ...r, [k]: v } : r)));
    const setCommission = (i, k, v) => setData('commissions', data.commissions.map((r, idx) => (idx === i ? { ...r, [k]: v } : r)));
    const addExpense = () => setData('expenses', [...data.expenses, { expense_category_id: '', description: '', amount: '' }]);
    const addCommission = () => setData('commissions', [...data.commissions, { label: '', amount: '', type: 'charged_to_customer', reference_id: '' }]);
    const removeExpense = (i) => setData('expenses', data.expenses.filter((_, idx) => idx !== i));
    const removeCommission = (i) => setData('commissions', data.commissions.filter((_, idx) => idx !== i));

    const submit = (e) => {
        e.preventDefault();
        const opts = onDone ? { onSuccess: () => { reset(); onDone(); } } : {};
        editing ? put(route('transactions.update', transaction.id), opts) : post(route('transactions.store'), opts);
    };

    return (
        <form onSubmit={submit} className="grid grid-cols-1 gap-6 xl:grid-cols-3">
            <div className="space-y-6 xl:col-span-2">
                <Card title="Basic Information">
                    <div className="grid grid-cols-2 gap-4 md:grid-cols-3">
                        <Field label="Transaction Date" required error={errors.transaction_date}>
                            <input type="date" className={input} value={data.transaction_date} onChange={(e) => setData('transaction_date', e.target.value)} />
                        </Field>
                        <Field label="Invoice Number" error={errors.invoice_no}>
                            <input className={input} value={data.invoice_no} onChange={(e) => setData('invoice_no', e.target.value)} />
                        </Field>
                        <Field label="BOE Number" error={errors.boe_no}>
                            <input className={input} value={data.boe_no} onChange={(e) => setData('boe_no', e.target.value)} />
                        </Field>
                        <Field label="Customer" required error={errors.customer_id}>
                            <ComboBox
                                options={customers.map((c) => ({ value: c.id, label: c.name }))}
                                value={data.customer_id} onChange={(v) => setData('customer_id', v)}
                                placeholder="Select customer…" createSlug="customers" createField="name"
                            />
                        </Field>
                        <Field label="Reference" error={errors.reference_id}>
                            <ComboBox
                                options={references.map((r) => ({ value: r.id, label: r.name }))}
                                value={data.reference_id} onChange={(v) => setData('reference_id', v)}
                                placeholder="—" createSlug="references" createField="name"
                            />
                        </Field>
                        <Field label="Vehicle" error={errors.vehicle_id}>
                            <ComboBox
                                options={vehicles.map((v) => ({ value: v.id, label: v.number }))}
                                value={data.vehicle_id} onChange={(v) => setData('vehicle_id', v)}
                                placeholder="—" createSlug="vehicles" createField="number"
                            />
                        </Field>
                    </div>
                </Card>

                <Card title="Income Details">
                    <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
                        <Field label="Customs Fees (CDR)" required error={errors.customs_fees}>
                            <input type="number" step="0.01" className={input} value={data.customs_fees} onChange={(e) => setData('customs_fees', e.target.value)} />
                        </Field>
                        <Field label="Government Fees" required error={errors.gov_fees}>
                            <input type="number" step="0.01" className={input} value={data.gov_fees} onChange={(e) => setData('gov_fees', e.target.value)} />
                        </Field>
                        <Field label="Profit" required error={errors.profit}>
                            <input type="number" step="0.01" className={input} value={data.profit} onChange={(e) => setData('profit', e.target.value)} />
                        </Field>
                        <Field label="VAT Rate (%)" error={errors.vat_rate}>
                            <input type="number" step="0.01" className={input} value={data.vat_rate} onChange={(e) => setData('vat_rate', e.target.value)} />
                        </Field>
                    </div>
                </Card>

                <Card title="Expenses" action={<button type="button" onClick={addExpense} className="text-xs font-semibold text-primary-600 hover:underline">+ Add row</button>}>
                    <div className="space-y-2">
                        {data.expenses.map((row, i) => (
                            <div key={i} className="grid grid-cols-12 gap-2">
                                <select className={input + ' col-span-4'} value={row.expense_category_id} onChange={(e) => setExpense(i, 'expense_category_id', e.target.value)}>
                                    <option value="">Category…</option>
                                    {expenseCategories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                                </select>
                                <input className={input + ' col-span-5'} placeholder="Description" value={row.description} onChange={(e) => setExpense(i, 'description', e.target.value)} />
                                <input type="number" step="0.01" className={input + ' col-span-2'} placeholder="Amount" value={row.amount} onChange={(e) => setExpense(i, 'amount', e.target.value)} />
                                <button type="button" onClick={() => removeExpense(i)} className="col-span-1 text-accent-red hover:text-accent-red-dark">✕</button>
                            </div>
                        ))}
                    </div>
                </Card>

                <Card title="Commissions" action={<button type="button" onClick={addCommission} className="text-xs font-semibold text-primary-600 hover:underline">+ Add row</button>}>
                    <div className="space-y-2">
                        {data.commissions.map((row, i) => (
                            <div key={i} className="grid grid-cols-12 gap-2">
                                <input className={input + ' col-span-3'} placeholder="Label (Com-1)" value={row.label} onChange={(e) => setCommission(i, 'label', e.target.value)} />
                                <select className={input + ' col-span-4'} value={row.type} onChange={(e) => setCommission(i, 'type', e.target.value)}>
                                    <option value="charged_to_customer">Charged to customer</option>
                                    <option value="paid_to_reference">Paid to reference</option>
                                </select>
                                <select className={input + ' col-span-3'} value={row.reference_id} onChange={(e) => setCommission(i, 'reference_id', e.target.value)}>
                                    <option value="">Reference…</option>
                                    {references.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
                                </select>
                                <input type="number" step="0.01" className={input + ' col-span-1'} placeholder="Amt" value={row.amount} onChange={(e) => setCommission(i, 'amount', e.target.value)} />
                                <button type="button" onClick={() => removeCommission(i)} className="col-span-1 text-accent-red hover:text-accent-red-dark">✕</button>
                            </div>
                        ))}
                    </div>
                </Card>

                {customFields.length > 0 && (
                    <Card title="Additional Details">
                        <div className="grid grid-cols-2 gap-4 md:grid-cols-3">
                            {customFields.map((cf) => (
                                <Field key={cf.key} label={cf.label} required={cf.required} error={errors['custom_data.' + cf.key]}>
                                    {cf.type === 'select' ? (
                                        <select className={input} value={data.custom_data[cf.key] ?? ''} onChange={(e) => setCustom(cf.key, e.target.value)}>
                                            <option value="">Select…</option>
                                            {(cf.options || []).map((o) => <option key={o} value={o}>{o}</option>)}
                                        </select>
                                    ) : (
                                        <input
                                            type={cf.type === 'number' ? 'number' : cf.type === 'date' ? 'date' : 'text'}
                                            step={cf.type === 'number' ? 'any' : undefined}
                                            className={input}
                                            value={data.custom_data[cf.key] ?? ''}
                                            onChange={(e) => setCustom(cf.key, e.target.value)}
                                        />
                                    )}
                                </Field>
                            ))}
                        </div>
                    </Card>
                )}
            </div>

            <div className="space-y-6">
                <Card title="Payment">
                    <div className="space-y-4">
                        <Field label="Currency" error={errors.currency}>
                            <select className={input} value={data.currency} onChange={(e) => setData('currency', e.target.value)}>
                                <option value="AED">AED — UAE Dirham</option>
                                <option value="OMR">OMR — Omani Rial</option>
                            </select>
                        </Field>
                        <Field label="Payment Method" required error={errors.payment_method_id}>
                            <select className={input} value={data.payment_method_id} onChange={(e) => setData('payment_method_id', e.target.value)}>
                                <option value="">Select…</option>
                                {paymentMethods.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                            </select>
                        </Field>
                        <Field label="Credit Amount" error={errors.credit_amount}>
                            <input type="number" step="0.01" className={input} value={data.credit_amount} onChange={(e) => setData('credit_amount', e.target.value)} />
                        </Field>
                        <Field label="Remarks" error={errors.remarks}>
                            <textarea rows="2" className={input} value={data.remarks} onChange={(e) => setData('remarks', e.target.value)} />
                        </Field>
                    </div>
                </Card>

                <Card title="Auto-Calculated Totals">
                    <dl className="space-y-2 text-sm">
                        <Row label="VAT Amount" value={totals.vat_amount} currency={data.currency} />
                        <Row label="Total Amount" value={totals.total_amount} strong currency={data.currency} />
                        <Row label="Total Expenses" value={totals.total_expenses} currency={data.currency} />
                        <Row label="Commission (payable)" value={totals.commission_payable} currency={data.currency} />
                        <div className="my-2 border-t" />
                        <Row label="Grand Total" value={totals.grand_total} big accent="primary" currency={data.currency} />
                        <Row label="Net Profit" value={totals.net_profit} big accent="green" currency={data.currency} />
                    </dl>
                </Card>

                <div className="flex gap-2">
                    <button type="submit" disabled={processing} className="flex-1 rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white shadow hover:bg-primary-700 disabled:opacity-50">
                        {editing ? 'Update Transaction' : 'Save Transaction'}
                    </button>
                    <Link href={route('transactions.index')} className="rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-50">
                        Cancel
                    </Link>
                </div>
            </div>
        </form>
    );
}
