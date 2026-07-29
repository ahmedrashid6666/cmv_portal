import { createElement, Fragment } from 'react';

// Format a number: no decimals when it's a whole amount, two decimals when it
// has a fractional part. Always thousands-separated. e.g. 11450 → "11,450",
// 200.5 → "200.50".
export const num = (n) => {
    const v = Math.round(Number(n || 0) * 100) / 100;
    const hasFraction = v % 1 !== 0;
    return new Intl.NumberFormat('en-AE', {
        minimumFractionDigits: hasFraction ? 2 : 0,
        maximumFractionDigits: 2,
    }).format(v);
};

// AED is the default currency and is shown without a currency label.
export const AED = (n) => num(n);

// Currency-aware amount. AED (the default) shows just the number; any other
// currency (e.g. OMR) renders the code as a small tag beside the amount.
export const money = (n, currency = 'AED') => {
    const text = num(n);
    if (!currency || currency === 'AED') return text;

    return createElement(
        Fragment,
        null,
        text,
        createElement(
            'sup',
            { className: 'ml-0.5 rounded bg-amber-100 px-1 text-[9px] font-semibold uppercase tracking-wide text-amber-700' },
            currency,
        ),
    );
};

export const CURRENCIES = ['AED', 'OMR'];

// Display a date as DD-MM-YYYY, and any time in 12-hour format (e.g. 06:31 PM).
// Accepts 'YYYY-MM-DD', ISO datetime, or 'YYYY-MM-DD HH:mm'.
// Returns the input unchanged if it isn't a recognisable date.
export const fmtDate = (value) => {
    if (!value) return '';
    const m = String(value).match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
    if (!m) return value;
    const [, y, mo, d, hh, mm] = m;
    let time = '';
    if (hh !== undefined) {
        let h = parseInt(hh, 10);
        const ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        time = ` ${String(h).padStart(2, '0')}:${mm} ${ampm}`;
    }
    return `${d}-${mo}-${y}${time}`;
};
