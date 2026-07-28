import ComboBox from '@/Components/ComboBox';
import { money } from '@/lib/format';
import { useForm } from '@inertiajs/react';
import { useMemo } from 'react';

const input = 'w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';

const statusStyle = {
    pending: 'bg-red-100 text-accent-red-dark',
    partial: 'bg-amber-100 text-amber-700',
    returned: 'bg-emerald-100 text-emerald-700',
};

function Field({ label, error, required, children }) {
    return (
        <label className="block">
            <span className="mb-1 block text-xs font-medium text-slate-600">{label} {required && <span className="text-accent-red">*</span>}</span>
            {children}
            {error && <span className="mt-1 block text-xs text-accent-red">{error}</span>}
        </label>
    );
}

/**
 * Add / edit form for a Daily-Credit or Borrowed ledger entry.
 * `meta` = { slug, type, label, partyLabel, paidLabel }.
 */
export default function LedgerEntryForm({ meta, entry, labels, onDone, customers = [], references = [], vehicles = [] }) {
    const editing = !!entry;
    const isBorrowed = meta.type === 'borrowed';
    const partyOptions = (isBorrowed ? references : customers).map((x) => ({ value: x.name, label: x.name, sublabel: isBorrowed ? x.company : undefined }));
    const partySlug = isBorrowed ? 'references' : 'customers';
    const refOptions = references.map((r) => ({ value: r.name, label: r.name, sublabel: r.company }));
    const vehOptions = vehicles.map((v) => ({ value: v.number, label: v.number }));
    const blank = {
        entry_date: entry?.entry_date ?? new Date().toISOString().slice(0, 10),
        party_name: entry?.party_name ?? '',
        reference: entry?.reference ?? '',
        vehicle_number: entry?.vehicle_number ?? '',
        total_amount: entry?.total_amount ?? '',
        currency: entry?.currency ?? 'AED',
        paid_amount: entry?.paid_amount ?? 0,
        return_date: entry?.return_date ?? '',
        remarks: entry?.remarks ?? '',
    };
    const { data, setData, post, put, processing, errors, reset } = useForm(blank);

    const liveBalance = useMemo(() => Math.max(0, (parseFloat(data.total_amount) || 0) - (parseFloat(data.paid_amount) || 0)), [data.total_amount, data.paid_amount]);
    const liveStatus = (parseFloat(data.paid_amount) || 0) <= 0 ? 'pending' : liveBalance <= 0 ? 'returned' : 'partial';

    const submit = (ev) => {
        ev.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => { reset(); onDone && onDone(); } };
        editing ? put(route('ledger.update', [meta.slug, entry.id]), opts) : post(route('ledger.store', meta.slug), opts);
    };

    return (
        <form onSubmit={submit} className="space-y-3">
            <Field label="Date" error={errors.entry_date}>
                <input type="date" className={input} value={data.entry_date} onChange={(e) => setData('entry_date', e.target.value)} />
            </Field>
            <Field label={meta.partyLabel} error={errors.party_name} required>
                <ComboBox options={partyOptions} value={data.party_name} onChange={(v) => setData('party_name', v)} placeholder={`Select or add ${meta.partyLabel}`} createSlug={partySlug} createField="name" valueField="label" />
            </Field>
            <div className="grid grid-cols-2 gap-3">
                <Field label="Reference" error={errors.reference}>
                    <ComboBox options={refOptions} value={data.reference} onChange={(v) => setData('reference', v)} placeholder="—" createSlug="references" createField="name" valueField="label" />
                </Field>
                <Field label="Vehicle No" error={errors.vehicle_number}>
                    <ComboBox options={vehOptions} value={data.vehicle_number} onChange={(v) => setData('vehicle_number', v)} placeholder="—" createSlug="vehicles" createField="number" valueField="label" />
                </Field>
            </div>
            <Field label="Currency" error={errors.currency}>
                <select className={input} value={data.currency} onChange={(e) => setData('currency', e.target.value)}>
                    <option value="AED">AED — UAE Dirham</option>
                    <option value="OMR">OMR — Omani Rial</option>
                </select>
            </Field>
            <div className="grid grid-cols-2 gap-3">
                <Field label="Total Amount" error={errors.total_amount} required>
                    <input type="number" step="0.01" className={input} value={data.total_amount} onChange={(e) => setData('total_amount', e.target.value)} />
                </Field>
                <Field label={meta.paidLabel} error={errors.paid_amount}>
                    <input type="number" step="0.01" className={input} value={data.paid_amount} onChange={(e) => setData('paid_amount', e.target.value)} />
                </Field>
            </div>

            <div className="rounded-lg bg-slate-50 p-3 text-sm">
                <div className="flex items-center justify-between">
                    <span className="text-slate-500">Balance</span>
                    <span className="text-lg font-bold text-accent-red">{money(liveBalance, data.currency)}</span>
                </div>
                <div className="mt-1 flex items-center justify-between">
                    <span className="text-slate-500">Status</span>
                    <span className={'rounded-full px-2 py-0.5 text-xs font-semibold ' + statusStyle[liveStatus]}>{labels[liveStatus]}</span>
                </div>
            </div>

            <Field label={`Return Date (auto-set when fully ${isBorrowed ? 'returned' : 'paid'})`} error={errors.return_date}>
                <input type="date" className={input} value={data.return_date} onChange={(e) => setData('return_date', e.target.value)} />
            </Field>
            <Field label="Remarks" error={errors.remarks}>
                <textarea rows="2" className={input} value={data.remarks} onChange={(e) => setData('remarks', e.target.value)} />
            </Field>
            <button disabled={processing} className="w-full rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-primary-700 disabled:opacity-50">
                {editing ? 'Update Entry' : 'Add Entry'}
            </button>
        </form>
    );
}
