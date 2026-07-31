const input = 'w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';

/**
 * Repeatable contact-number input used by every entry form. Always shows at
 * least one row; the "+ Add number" button appends another, and each extra row
 * can be removed. Blank rows are dropped server-side before saving.
 */
export default function ContactNumbers({ label = 'Contact Number', value, onChange, error }) {
    const numbers = value && value.length ? value : [''];

    const update = (i, v) => onChange(numbers.map((n, idx) => (idx === i ? v : n)));
    const add = () => onChange([...numbers, '']);
    const remove = (i) => {
        const next = numbers.filter((_, idx) => idx !== i);
        onChange(next.length ? next : ['']);
    };

    return (
        <div className="block">
            <span className="mb-1 block text-xs font-medium text-slate-600">{label}</span>
            <div className="space-y-2">
                {numbers.map((n, i) => (
                    <div key={i} className="flex items-center gap-2">
                        <input
                            type="tel"
                            inputMode="tel"
                            className={input}
                            placeholder="05X XXX XXXX"
                            value={n ?? ''}
                            onChange={(e) => update(i, e.target.value)}
                        />
                        {numbers.length > 1 && (
                            <button
                                type="button"
                                onClick={() => remove(i)}
                                aria-label="Remove number"
                                className="shrink-0 rounded-lg border border-slate-200 px-2.5 py-1.5 text-accent-red hover:bg-red-50"
                            >
                                ✕
                            </button>
                        )}
                    </div>
                ))}
            </div>
            <button type="button" onClick={add} className="mt-2 text-xs font-semibold text-primary-600 hover:underline">
                + Add number
            </button>
            {error && <span className="mt-1 block text-xs text-accent-red">{error}</span>}
        </div>
    );
}
