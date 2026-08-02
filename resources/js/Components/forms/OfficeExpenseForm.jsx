import ComboBox from '@/Components/ComboBox';
import ContactNumbers from '@/Components/forms/ContactNumbers';
import focusNextFieldOnEnter from '@/lib/focusNextFieldOnEnter';
import { todayLocalISO } from '@/lib/date';
import { useForm } from '@inertiajs/react';

const input = 'w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';

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

/**
 * Standalone office / overhead expense entry (rent, salary, utilities, …).
 * Not tied to a shipment — it is a direct cash/bank outflow.
 */
export default function OfficeExpenseForm({ officeExpense = null, expenseCategories = [], paymentMethods = [], onDone }) {
    const editing = !!officeExpense;
    const { data, setData, post, put, processing, errors, reset } = useForm({
        expense_date: officeExpense?.expense_date ?? todayLocalISO(),
        expense_category_id: officeExpense?.expense_category_id ?? '',
        description: officeExpense?.description ?? '',
        amount: officeExpense?.amount ?? '',
        currency: officeExpense?.currency ?? 'AED',
        payment_method_id: officeExpense?.payment_method_id ?? '',
        contact_numbers: officeExpense?.contact_numbers?.length ? officeExpense.contact_numbers : [''],
        remarks: officeExpense?.remarks ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        if (editing) {
            put(route('office-expenses.update', officeExpense.id), { onSuccess: () => onDone?.() });
        } else {
            post(route('office-expenses.store'), { onSuccess: () => { reset(); onDone?.(); } });
        }
    };

    return (
        <form onSubmit={submit} onKeyDown={focusNextFieldOnEnter} className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
                <Field label="Date" required error={errors.expense_date}>
                    <input type="date" className={input} value={data.expense_date} onChange={(e) => setData('expense_date', e.target.value)} />
                </Field>
                <Field label="Amount" required error={errors.amount}>
                    <input type="number" step="0.01" min="0" className={input} placeholder="0.00" value={data.amount} onChange={(e) => setData('amount', e.target.value)} />
                </Field>
            </div>

            <Field label="Category" error={errors.expense_category_id}>
                <ComboBox
                    options={expenseCategories.map((c) => ({ value: c.id, label: c.name }))}
                    value={data.expense_category_id}
                    onChange={(v) => setData('expense_category_id', v)}
                    placeholder="Select category…"
                    createSlug="expense-categories"
                    createField="name"
                />
            </Field>

            <Field label="Description" error={errors.description}>
                <input className={input} placeholder="e.g. Office rent — July" value={data.description} onChange={(e) => setData('description', e.target.value)} />
            </Field>

            <ContactNumbers value={data.contact_numbers} onChange={(v) => setData('contact_numbers', v)} error={errors.contact_numbers} />

            <div className="grid grid-cols-2 gap-4">
                <Field label="Currency" error={errors.currency}>
                    <select className={input} value={data.currency} onChange={(e) => setData('currency', e.target.value)}>
                        <option value="AED">AED — UAE Dirham</option>
                        <option value="OMR">OMR — Omani Rial</option>
                    </select>
                </Field>
                <Field label="Paid Via" required error={errors.payment_method_id}>
                    <select className={input} value={data.payment_method_id} onChange={(e) => setData('payment_method_id', e.target.value)}>
                        <option value="">Select…</option>
                        {paymentMethods.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                    </select>
                </Field>
            </div>

            <Field label="Remarks" error={errors.remarks}>
                <textarea rows="2" className={input} value={data.remarks} onChange={(e) => setData('remarks', e.target.value)} />
            </Field>

            <button type="submit" disabled={processing} className="w-full rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white shadow hover:bg-primary-700 disabled:opacity-50">
                {editing ? 'Update Office Expense' : 'Save Office Expense'}
            </button>
        </form>
    );
}
