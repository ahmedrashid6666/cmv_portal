import { useEffect, useRef, useState } from 'react';

export default function AutocompleteInput({ options = [], value, onChange, placeholder = '', className = '' }) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState(value || '');
    const inputRef = useRef(null);
    const wrapRef = useRef(null);

    useEffect(() => {
        setQuery(value || '');
    }, [value]);

    useEffect(() => {
        const handleClickOutside = (e) => {
            if (wrapRef.current && !wrapRef.current.contains(e.target)) {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    const q = query.trim().toLowerCase();
    const filtered = q ? options.filter((o) => o.label.toLowerCase().includes(q)) : options;

    const handleSelect = (opt) => {
        onChange(opt.value);
        setQuery(opt.value);
        setOpen(false);
    };

    const handleInputChange = (e) => {
        const newValue = e.target.value;
        setQuery(newValue);
        onChange(newValue);
        setOpen(true);
    };

    const base = 'w-full rounded-lg border border-slate-300 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 px-3 py-2';

    return (
        <div className={'relative ' + className} ref={wrapRef}>
            <input
                ref={inputRef}
                type="text"
                value={query}
                onChange={handleInputChange}
                onFocus={() => setOpen(true)}
                placeholder={placeholder}
                className={base}
            />

            {open && filtered.length > 0 && (
                <div className="absolute z-30 mt-1 w-full rounded-lg border border-slate-200 bg-white shadow-lg">
                    <ul className="max-h-56 overflow-y-auto py-1 text-sm">
                        {filtered.map((o) => (
                            <li key={o.value}>
                                <button
                                    type="button"
                                    onClick={() => handleSelect(o)}
                                    className={'w-full px-3 py-1.5 text-left hover:bg-slate-50 ' + (query === o.value ? 'bg-primary-50 font-medium text-primary-700' : 'text-navy-800')}
                                >
                                    <span className="block truncate">{o.label}</span>
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}
