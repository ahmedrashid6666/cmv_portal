import AutocompleteInput from '@/Components/AutocompleteInput';
import ContactNumbers from '@/Components/forms/ContactNumbers';
import { money } from '@/lib/format';
import { todayLocalISO } from '@/lib/date';
import focusNextFieldOnEnter from '@/lib/focusNextFieldOnEnter';
import { useForm } from '@inertiajs/react';
import { useEffect, useMemo } from 'react';

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
export default function LedgerEntryForm({ meta, entry, labels, onDone, customers = [], references = [] }) {
    const editing = !!entry;
    const isBorrowed = meta.type === 'borrowed';
    const partyOptions = (isBorrowed ? references : customers).map((x) => ({ value: x.name, label: x.name, sublabel: isBorrowed ? x.company : undefined }));
    const partySlug = isBorrowed ? 'references' : 'customers';
    const refOptions = references.map((r) => ({ value: r.name, label: r.name, sublabel: r.company }));
    const blank = {
        entry_date: entry?.entry_date ?? todayLocalISO(),
        party_name: entry?.party_name ?? '',
        contact_numbers: entry?.contact_numbers?.length ? entry.contact_numbers : [''],
        reference: entry?.reference ?? '',
        vehicle_number: entry?.vehicle_number ?? '',
        total_amount: entry?.total_amount ?? '',
        currency: entry?.currency ?? 'AED',
        paid_amount: entry?.paid_amount ?? 0,
        return_date: entry?.return_date ?? '',
        remarks: entry?.remarks ?? '',
        details: entry?.details?.length
            ? entry.details.map((d) => ({ detail_date: d.detail_date, description: d.description ?? '', amount: d.amount ?? '' }))
            // A brand-new entry starts with one blank row; an existing entry saved
            // before this feature existed keeps its manually-entered total instead.
            : (entry ? [] : [{ detail_date: todayLocalISO(), description: '', amount: '' }]),
    };
    const { data, setData, post, put, processing, errors, reset } = useForm(blank);

    const detailsTotal = useMemo(() => data.details.reduce((s, d) => s + (parseFloat(d.amount) || 0), 0), [data.details]);
    // Only switch into computed-total mode once a row actually has an amount —
    // a fresh, still-blank default row shouldn't lock Total Amount to 0.
    const hasDetails = data.details.some((d) => d.amount !== '' && d.amount !== null && d.amount !== undefined);
    const totalAmount = hasDetails ? detailsTotal : (parseFloat(data.total_amount) || 0);

    const addDetail = () => setData('details', [...data.details, { detail_date: todayLocalISO(), description: '', amount: '' }]);
    const updateDetail = (i, key, value) => setData('details', data.details.map((row, idx) => (idx === i ? { ...row, [key]: value } : row)));
    const removeDetail = (i) => setData('details', data.details.filter((_, idx) => idx !== i));

    const liveBalance = useMemo(() => Math.max(0, totalAmount - (parseFloat(data.paid_amount) || 0)), [totalAmount, data.paid_amount]);
    const liveStatus = (parseFloat(data.paid_amount) || 0) <= 0 ? 'pending' : liveBalance <= 0 ? 'returned' : 'partial';

    // Detail rows are the source of truth for the total once any exist — keep the submitted value in sync.
    useEffect(() => {
        if (hasDetails) setData('total_amount', String(detailsTotal));
    }, [hasDetails, detailsTotal]);

    const submit = (ev) => {
        ev.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => { reset(); onDone && onDone(); } };
        editing ? put(route('ledger.update', [meta.slug, entry.id]), opts) : post(route('ledger.store', meta.slug), opts);
    };

    return (
        <form onSubmit={submit} onKeyDown={focusNextFieldOnEnter} className="space-y-3">
            <Field label="Date" error={errors.entry_date}>
                <input type="date" className={input} value={data.entry_date} onChange={(e) => setData('entry_date', e.target.value)} />
            </Field>
            <Field label={meta.partyLabel} error={errors.party_name} required>
                <AutocompleteInput options={partyOptions} value={data.party_name} onChange={(v) => setData('party_name', v)} placeholder={`Type ${meta.partyLabel}`} />
            </Field>
            <ContactNumbers value={data.contact_numbers} onChange={(v) => setData('contact_numbers', v)} error={errors.contact_numbers} />
            <div className="grid grid-cols-2 gap-3">
                <Field label="Reference" error={errors.reference}>
                    <AutocompleteInput options={refOptions} value={data.reference} onChange={(v) => setData('reference', v)} placeholder="Type reference" />
                </Field>
                <Field label="Vehicle No" error={errors.vehicle_number}>
                    <input className={input} value={data.vehicle_number} onChange={(e) => setData('vehicle_number', e.target.value)} placeholder="—" />
                </Field>
            </div>
            <Field label="Currency" error={errors.currency}>
                <select className={input} value={data.currency} onChange={(e) => setData('currency', e.target.value)}>
                    <option value="AED">AED — UAE Dirham</option>
                    <option value="OMR">OMR — Omani Rial</option>
                </select>
            </Field>
            <div>
                <span className="mb-1 block text-xs font-medium text-slate-600">Details</span>
                {data.details.length > 0 && (
                    <div className="space-y-2">
                        {data.details.map((row, i) => (
                            <div key={i} className="grid grid-cols-12 gap-2">
                                <input type="date" className={input + ' col-span-3'} value={row.detail_date} onChange={(e) => updateDetail(i, 'detail_date', e.target.value)} />
                                <input className={input + ' col-span-6'} placeholder="Description" value={row.description} onChange={(e) => updateDetail(i, 'description', e.target.value)} />
                                <input type="number" step="0.01" className={input + ' col-span-2'} placeholder="0.00" value={row.amount} onChange={(e) => updateDetail(i, 'amount', e.target.value)} />
                                <button type="button" onClick={() => removeDetail(i)} className="col-span-1 text-accent-red hover:text-accent-red-dark">✕</button>
                            </div>
                        ))}
                    </div>
                )}
                <button type="button" onClick={addDetail} className="mt-2 text-xs font-semibold text-primary-600 hover:underline">+ Add row</button>
            </div>
            <div className="grid grid-cols-2 gap-3">
                <Field label={meta.totalLabel} error={errors.total_amount} required>
                    <input type="number" step="0.01" className={input + (hasDetails ? ' bg-slate-50 text-slate-500' : '')}
                        value={hasDetails ? totalAmount : data.total_amount} disabled={hasDetails}
                        onChange={(e) => setData('total_amount', e.target.value)} />
                    {hasDetails && <span className="mt-1 block text-[11px] text-slate-400">Sum of {data.details.length} detail row{data.details.length > 1 ? 's' : ''}</span>}
                </Field>
                <Field label={meta.paidLabel} error={errors.paid_amount}>
                    <input type="number" step="0.01" className={input} value={data.paid_amount} onChange={(e) => setData('paid_amount', e.target.value)} />
                </Field>
            </div>
            <Field label="Remarks" error={errors.remarks}>
                <textarea rows="2" className={input} value={data.remarks} onChange={(e) => setData('remarks', e.target.value)} />
            </Field>

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
            <button disabled={processing} className="w-full rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-primary-700 disabled:opacity-50">
                {editing ? 'Update Entry' : 'Add Entry'}
            </button>
        </form>
    );
}
