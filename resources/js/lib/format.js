export const AED = (n) =>
    new Intl.NumberFormat('en-AE', { style: 'currency', currency: 'AED', minimumFractionDigits: 2 }).format(
        Number(n || 0),
    );

export const num = (n) =>
    new Intl.NumberFormat('en-AE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(n || 0));

// Currency-aware amount, e.g. money(500, 'OMR') → "OMR 500.00". Defaults to AED.
export const money = (n, currency = 'AED') => `${currency} ${num(n)}`;

export const CURRENCIES = ['AED', 'OMR'];

// Display a date as DD/MM/YYYY. Accepts 'YYYY-MM-DD', ISO datetime, or 'YYYY-MM-DD HH:mm'.
// Returns the input unchanged if it isn't a recognisable date.
export const fmtDate = (value) => {
    if (!value) return '';
    const m = String(value).match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}:\d{2}))?/);
    if (!m) return value;
    const [, y, mo, d, time] = m;
    return `${d}/${mo}/${y}` + (time ? ` ${time}` : '');
};
