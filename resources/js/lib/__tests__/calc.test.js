import { describe, expect, it } from 'vitest';
import { computeFinalCalculation, computeTotals } from '../calc';

describe('computeTotals (mirror of TransactionCalculator.php)', () => {
    it('matches workbook row 7: 345 total, 370 grand with 25 to customer', () => {
        const r = computeTotals({
            customs_fees: 295, gov_fees: 0, profit: 50, vat_rate: 0,
            commissions: [{ type: 'charged_to_customer', amount: 25 }],
        });
        expect(r.total_amount).toBe(345);
        expect(r.grand_total).toBe(370);
    });

    it('nets profit against expenses and payable commission', () => {
        const r = computeTotals({
            customs_fees: 295, gov_fees: 0, profit: 50, vat_rate: 0,
            expenses: [{ amount: 27 }],
            commissions: [{ type: 'paid_to_reference', amount: 10 }],
        });
        expect(r.total_expenses).toBe(27);
        expect(r.commission_payable).toBe(10);
        expect(r.net_profit).toBe(13);
    });

    it('applies a non-zero vat rate', () => {
        const r = computeTotals({ customs_fees: 100, gov_fees: 0, profit: 0, vat_rate: 5 });
        expect(r.vat_amount).toBe(5);
        expect(r.total_amount).toBe(105);
    });

    it('includes other_amount in the taxable base, same as gov_fees', () => {
        const r = computeTotals({ customs_fees: 245, gov_fees: 10, other_amount: 15, profit: 35, vat_rate: 0 });
        expect(r.total_amount).toBe(305);
    });
});

describe('computeFinalCalculation (mirror of FinalCalculationService::compute)', () => {
    it('reproduces the spreadsheet totals exactly', () => {
        const r = computeFinalCalculation({
            opening_balance: 64061, total_income: 15793, customs_gov_fees: 11688,
            credit_unpaid: 8850, office_expenses: 2434,
            borrowed_amount: 89700, daily_credit_pending: 58069,
            bank_ac_balance: 56684, cdr_ac_balance: 19927,
            aed_counted: 0, omr_counted: 0, omr_rate: 9.5238,
        });
        expect(r.total_amount).toBe(56882);
        expect(r.total_balance_amount).toBe(88513);
        expect(r.total_cash_balance).toBe(11902);
        expect(r.cash_extra).toBe(-11902);
    });

    it('converts OMR counted cash to AED at the given rate', () => {
        const r = computeFinalCalculation({ aed_counted: 100, omr_counted: 10, omr_rate: 9.5 });
        expect(r.cash_counted).toBe(195);
        expect(r.cash_extra).toBe(195);
    });

    it('treats missing fields as zero and defaults the OMR rate', () => {
        const r = computeFinalCalculation({});
        expect(r.total_amount).toBe(0);
        expect(r.total_cash_balance).toBe(0);
        expect(r.cash_extra).toBe(0);
    });
});
