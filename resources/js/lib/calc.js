// Mirrors app/Services/TransactionCalculator.php for instant on-screen totals.
// The server remains the source of truth on save.
const n = (v) => {
    const x = parseFloat(v);
    return Number.isFinite(x) ? x : 0;
};
const r2 = (x) => Math.round((x + Number.EPSILON) * 100) / 100;

export function computeTotals({ customs_fees, gov_fees, other_amount, profit, vat_rate, expenses = [], commissions = [] }) {
    const taxable = n(customs_fees) + n(gov_fees) + n(other_amount) + n(profit);
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

// Mirrors app/Services/FinalCalculationService::compute() for instant on-screen totals.
export function computeFinalCalculation(data, defaultRate = 9.5238) {
    const rate = data.omr_rate === '' || data.omr_rate === null || data.omr_rate === undefined
        ? defaultRate
        : n(data.omr_rate);

    const openingBalance = n(data.opening_balance);
    const totalIncome = n(data.total_income);
    const customsGovFees = n(data.customs_gov_fees);
    const creditUnpaid = n(data.credit_unpaid);
    const officeExpenses = n(data.office_expenses);
    const totalAmount = r2(openingBalance + totalIncome - customsGovFees - creditUnpaid - officeExpenses);

    const borrowedAmount = n(data.borrowed_amount);
    const dailyCreditPending = n(data.daily_credit_pending);
    const totalBalanceAmount = r2(totalAmount + borrowedAmount - dailyCreditPending);

    const bankAcBalance = n(data.bank_ac_balance);
    const cdrAcBalance = n(data.cdr_ac_balance);
    const totalCashBalance = r2(totalBalanceAmount - bankAcBalance - cdrAcBalance);

    const aedCounted = n(data.aed_counted);
    const omrCounted = n(data.omr_counted);
    const cashCounted = r2(aedCounted + omrCounted * rate);
    const cashExtra = r2(cashCounted - totalCashBalance);

    return {
        total_amount: totalAmount,
        total_balance_amount: totalBalanceAmount,
        total_cash_balance: totalCashBalance,
        cash_counted: cashCounted,
        cash_extra: cashExtra,
    };
}
