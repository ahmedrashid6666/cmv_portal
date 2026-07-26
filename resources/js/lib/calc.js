// Mirrors app/Services/TransactionCalculator.php for instant on-screen totals.
// The server remains the source of truth on save.
const n = (v) => {
    const x = parseFloat(v);
    return Number.isFinite(x) ? x : 0;
};
const r2 = (x) => Math.round((x + Number.EPSILON) * 100) / 100;

export function computeTotals({ customs_fees, gov_fees, profit, vat_rate, expenses = [], commissions = [] }) {
    const taxable = n(customs_fees) + n(gov_fees) + n(profit);
    const vat = r2((taxable * n(vat_rate)) / 100);
    const total = r2(taxable + vat);

    const toCustomer = commissions
        .filter((c) => (c.type || 'charged_to_customer') === 'charged_to_customer')
        .reduce((s, c) => s + n(c.amount), 0);
    const payable = commissions
        .filter((c) => c.type === 'paid_to_reference')
        .reduce((s, c) => s + n(c.amount), 0);
    const totalExpenses = expenses.reduce((s, e) => s + n(e.amount), 0);

    return {
        vat_amount: vat,
        total_amount: total,
        grand_total: r2(total + toCustomer),
        total_expenses: r2(totalExpenses),
        commission_payable: r2(payable),
        net_profit: r2(n(profit) - totalExpenses - payable),
    };
}
