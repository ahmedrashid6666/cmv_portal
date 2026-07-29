import { describe, expect, it } from 'vitest';
import { num, AED, money } from '../format';

describe('num / AED — conditional decimals, no currency', () => {
    it('drops decimals for whole amounts', () => {
        expect(num(200)).toBe('200');
        expect(num(11450)).toBe('11,450');
        expect(AED(1030)).toBe('1,030');
    });
    it('keeps two decimals for fractional amounts', () => {
        expect(num(200.5)).toBe('200.50');
        expect(num(200.25)).toBe('200.25');
    });
});

describe('money', () => {
    it('returns a plain number string for AED', () => {
        expect(money(200, 'AED')).toBe('200');
        expect(money(200.5)).toBe('200.50');
    });
    it('returns a node carrying the code for other currencies', () => {
        const el = money(200, 'OMR');
        expect(typeof el).toBe('object'); // React element, not a string
    });
});
