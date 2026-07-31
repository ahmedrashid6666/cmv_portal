import { useEffect, useRef, useState } from 'react';

/**
 * Searchable dropdown that can also create a new value inline.
 *
 * options    : [{ value, label }]
 * value      : current selected value
 * onChange   : (value) => void
 * createSlug : master slug to POST new values to (customers|references|vehicles) or null to disable adding
 * createField: field name the master expects ('name' | 'number')
 * valueField : which part of the created record becomes the value — 'id' (transactions) or 'label' (ledger names)
 */
export default function ComboBox({ options = [], value, onChange, placeholder = 'Select…', createSlug = null, createField = 'name', valueField = 'id', className = '' }) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [created, setCreated] = useState([]); // items added inline this session
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const wrap = useRef(null);

    // Prop options + anything created inline (kept even as `options` re-renders).
    const opts = [...options, ...created.filter((c) => !options.some((o) => String(o.value) === String(c.value)))];

    useEffect(() => {
        const h = (e) => { if (wrap.current && !wrap.current.contains(e.target)) setOpen(false); };
        document.addEventListener('mousedown', h);
        return () => document.removeEventListener('mousedown', h);
    }, []);

    const selected = opts.find((o) => String(o.value) === String(value));
    const q = query.trim().toLowerCase();
    const filtered = q ? opts.filter((o) => o.label.toLowerCase().includes(q)) : opts;
    const exact = opts.some((o) => o.label.toLowerCase() === q);
    const canCreate = createSlug && query.trim() && !exact;

    const pick = (o) => { onChange(o.value); setOpen(false); setQuery(''); };

    const create = async () => {
        const label = query.trim();
        if (!label || !createSlug || busy) return;
        setBusy(true);
        setError('');
        try {
            const { data } = await window.axios.post(route('masters.quick', createSlug), { [createField]: label });
            const opt = { value: valueField === 'label' ? data.label : data.id, label: data.label };
            setCreated((prev) => (prev.some((p) => String(p.value) === String(opt.value)) ? prev : [...prev, opt]));
            onChange(opt.value);
            setOpen(false);
            setQuery('');
        } catch (e) {
            // Surface the real reason instead of failing silently.
            const status = e?.response?.status;
            const validation = e?.response?.data?.errors?.[createField]?.[0];
            setError(
                validation
                    || (status === 419 ? 'Session expired — please refresh the page and try again.'
                        : status === 403 ? 'You do not have permission to add here.'
                            : e?.response?.data?.message || 'Could not add. Please try again.')
            );
        } finally {
            setBusy(false);
        }
    };

    const base = 'w-full rounded-lg border border-slate-300 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';

    return (
        <div className={'relative ' + className} ref={wrap}>
            <button type="button" onClick={() => setOpen((o) => !o)} className={base + ' flex items-center justify-between px-3 py-2 text-left'}>
                <span className="min-w-0">
                    <span className={'block truncate ' + (selected ? 'text-navy-900' : 'text-slate-400')}>{selected ? selected.label : placeholder}</span>
                    {selected?.sublabel && <span className="mt-0.5 inline-block rounded bg-slate-100 px-1.5 py-0.5 text-[11px] leading-none text-slate-500">{selected.sublabel}</span>}
                </span>
                <span className="ml-2 text-slate-400">▾</span>
            </button>

            {open && (
                <div className="absolute z-30 mt-1 w-full rounded-lg border border-slate-200 bg-white shadow-lg">
                    <div className="border-b p-2">
                        <input
                            autoFocus
                            value={query}
                            onChange={(e) => { setQuery(e.target.value); if (error) setError(''); }}
                            onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); if (canCreate) create(); } }}
                            placeholder={createSlug ? 'Search or type to add…' : 'Search…'}
                            className="w-full rounded-md border-slate-300 text-sm focus:border-primary-500 focus:ring-primary-500"
                        />
                    </div>
                    <ul className="max-h-56 overflow-y-auto py-1 text-sm">
                        {createSlug && (
                            <li>
                                <button type="button" onClick={() => pick({ value: '', label: '' })} className="w-full px-3 py-1.5 text-left text-slate-400 hover:bg-slate-50">— None —</button>
                            </li>
                        )}
                        {filtered.map((o) => (
                            <li key={o.value}>
                                <button type="button" onClick={() => pick(o)} className={'w-full px-3 py-1.5 text-left hover:bg-slate-50 ' + (String(o.value) === String(value) ? 'bg-primary-50 font-medium text-primary-700' : 'text-navy-800')}>
                                    <span className="block truncate">{o.label}</span>
                                    {o.sublabel && <span className="mt-0.5 inline-block rounded bg-slate-100 px-1.5 py-0.5 text-[11px] leading-none text-slate-500">{o.sublabel}</span>}
                                </button>
                            </li>
                        ))}
                        {filtered.length === 0 && !canCreate && <li className="px-3 py-2 text-slate-400">No matches</li>}
                        {canCreate && (
                            <li className="border-t">
                                <button type="button" disabled={busy} onClick={create} className="flex w-full items-center gap-2 px-3 py-2 text-left font-semibold text-primary-600 hover:bg-primary-50 disabled:opacity-50">
                                    <span className="flex h-5 w-5 items-center justify-center rounded bg-primary-600 text-xs text-white">+</span>
                                    {busy ? 'Adding…' : `Add “${query.trim()}”`}
                                </button>
                            </li>
                        )}
                    </ul>
                    {error && <p className="border-t bg-red-50 px-3 py-2 text-xs text-accent-red-dark">{error}</p>}
                </div>
            )}
        </div>
    );
}
