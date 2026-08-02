// Local (not UTC) yyyy-mm-dd. Use instead of `Date#toISOString().slice(0, 10)`,
// which shifts to UTC and can land on the wrong day near midnight in
// timezones ahead of UTC (e.g. Gulf Standard Time).
export const toLocalISODate = (d) =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

export const todayLocalISO = () => toLocalISODate(new Date());
