import { AED, fmtDate } from '@/lib/format';
import { Card } from '@/Components/ui/Card';

export function BookTable({ book, inLabel = 'Debit (In)', outLabel = 'Credit (Out)' }) {
    return (
        <>
            <div className="mb-4 grid grid-cols-2 gap-4 md:grid-cols-4">
                <Stat label="Opening Balance" value={book.opening} />
                <Stat label={'Total ' + inLabel} value={book.totals.debit} accent="text-emerald-700" />
                <Stat label={'Total ' + outLabel} value={book.totals.credit} accent="text-accent-red" />
                <Stat label="Closing Balance" value={book.closing} accent="text-primary-700" strong />
            </div>

            <Card>
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left text-xs uppercase text-slate-500">
                                <th className="py-2 pr-4">Date</th>
                                <th className="py-2 pr-4">Description</th>
                                <th className="py-2 pr-4">Ref</th>
                                <th className="py-2 pr-4 text-right">{inLabel}</th>
                                <th className="py-2 pr-4 text-right">{outLabel}</th>
                                <th className="py-2 pr-4 text-right">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr className="border-b bg-slate-50 text-slate-500">
                                <td className="py-2 pr-4" colSpan="5">Opening balance</td>
                                <td className="py-2 pr-4 text-right font-semibold">{AED(book.opening)}</td>
                            </tr>
                            {book.rows.length === 0 && (
                                <tr><td colSpan="6" className="py-8 text-center text-slate-400">No entries for this period.</td></tr>
                            )}
                            {book.rows.map((r, i) => (
                                <tr key={i} className="border-b last:border-0 hover:bg-slate-50">
                                    <td className="py-2 pr-4 whitespace-nowrap">{fmtDate(r.date)}</td>
                                    <td className="py-2 pr-4">{r.description}</td>
                                    <td className="py-2 pr-4 text-slate-400">{r.ref || '—'}</td>
                                    <td className="py-2 pr-4 text-right text-emerald-700">{r.debit ? AED(r.debit) : '—'}</td>
                                    <td className="py-2 pr-4 text-right text-accent-red">{r.credit ? AED(r.credit) : '—'}</td>
                                    <td className="py-2 pr-4 text-right font-semibold tabular-nums text-navy-800">{AED(r.balance)}</td>
                                </tr>
                            ))}
                            <tr className="border-t-2 border-navy-200 bg-navy-50 font-semibold text-navy-800">
                                <td className="py-2 pr-4" colSpan="3">Closing balance</td>
                                <td className="py-2 pr-4 text-right text-emerald-700">{AED(book.totals.debit)}</td>
                                <td className="py-2 pr-4 text-right text-accent-red">{AED(book.totals.credit)}</td>
                                <td className="py-2 pr-4 text-right text-primary-700">{AED(book.closing)}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
        </>
    );
}

function Stat({ label, value, accent = 'text-navy-900', strong }) {
    return (
        <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <p className="text-xs uppercase text-slate-500">{label}</p>
            <p className={'mt-1 text-xl font-bold ' + accent + (strong ? ' text-2xl' : '')}>{AED(value)}</p>
        </div>
    );
}
