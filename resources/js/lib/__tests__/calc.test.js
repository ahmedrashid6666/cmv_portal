import { describe, expect, it } from 'vitest';
import { computeTotals } from '../calc';

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
});
