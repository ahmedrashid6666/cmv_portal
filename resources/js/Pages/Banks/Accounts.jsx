import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card } from '@/Components/ui/Card';
import { money } from '@/lib/format';
import { Head, Link } from '@inertiajs/react';

export default function BankAccounts({ banks, totals, combinedBankBalance }) {
    const unassigned = Math.round((combinedBankBalance - totals.balance) * 100) / 100;

    return (
        <AuthenticatedLayout header="Bank Accounts">
            <Head title="Bank Accounts" />

            <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <Stat label="Opening (all banks)" value={money(totals.opening, 'AED')} />
                <Stat label="Fees Paid Out" value={money(totals.customs_paid + totals.gov_paid, 'AED')} accent="text-accent-red" />
                <Stat label="Current Balance" value={money(totals.balance, 'AED')} accent="text-emerald-700" />
            </div>

            <Card title="Per-Bank Balances">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left text-xs uppercase text-slate-500">
                                <th className="py-2 pr-4">Bank</th>
                                <th className="py-2 pr-4">Account No</th>
                                <th className="py-2 pr-4 text-right">Opening</th>
                                <th className="py-2 pr-4 text-right">Customs Paid</th>
                                <th className="py-2 pr-4 text-right">Gov. Paid</th>
                                <th className="py-2 pr-4 text-right">Balance</th>
                                <th className="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {banks.length === 0 && <tr><td colSpan="7" className="py-8 text-center text-slate-400">No bank accounts yet. Add them under Master Data → Banks.</td></tr>}
                            {banks.map((b) => (
                                <tr key={b.id} className="border-b last:border-0 hover:bg-slate-50">
                                    <td className="py-2 pr-4 font-medium text-navy-800">
                                        {b.name}
                                        {b.is_customs && <span className="ml-2 rounded-full bg-primary-100 px-2 py-0.5 text-[10px] font-semibold text-primary-700">CUSTOMS / CDR</span>}
                                    </td>
                                    <td className="py-2 pr-4 text-slate-500">{b.account_no || '—'}</td>
                                    <td className="py-2 pr-4 text-right tabular-nums">{money(b.opening, 'AED')}</td>
                                    <td className="py-2 pr-4 text-right tabular-nums text-accent-red">{b.customs_paid ? '−' + money(b.customs_paid, 'AED') : '—'}</td>
                                    <td className="py-2 pr-4 text-right tabular-nums text-accent-red">{b.gov_paid ? '−' + money(b.gov_paid, 'AED') : '—'}</td>
                                    <td className={'py-2 pr-4 text-right font-semibold tabular-nums ' + (b.balance < 0 ? 'text-accent-red' : 'text-navy-900')}>{money(b.balance, 'AED')}</td>
                                    <td className="py-2 text-right">
                                        <Link href={route('bank-accounts.statement', b.id)} className="font-semibold text-primary-600 hover:underline">Statement</Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        {banks.length > 0 && (
                            <tfoot>
                                <tr className="border-t-2 font-semibold text-navy-900">
                                    <td className="py-2 pr-4" colSpan="2">Total</td>
                                    <td className="py-2 pr-4 text-right tabular-nums">{money(totals.opening, 'AED')}</td>
                                    <td className="py-2 pr-4 text-right tabular-nums text-accent-red">−{money(totals.customs_paid, 'AED')}</td>
                                    <td className="py-2 pr-4 text-right tabular-nums text-accent-red">−{money(totals.gov_paid, 'AED')}</td>
                                    <td className="py-2 pr-4 text-right tabular-nums">{money(totals.balance, 'AED')}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        )}
                    </table>
                </div>

                {Math.abs(unassigned) >= 0.01 && (
                    <p className="mt-3 rounded-lg bg-slate-50 p-3 text-xs text-slate-500">
                        The dashboard Bank Balance is <span className="font-semibold">{money(combinedBankBalance, 'AED')}</span>.
                        The difference of <span className="font-semibold">{money(unassigned, 'AED')}</span> is bank sales / repayments not tied to a specific account.
                    </p>
                )}
            </Card>
        </AuthenticatedLayout>
    );
}

function Stat({ label, value, accent = 'text-navy-900' }) {
    return (
        <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <p className="text-xs uppercase text-slate-500">{label}</p>
            <p className={'mt-1 text-2xl font-bold ' + accent}>{value}</p>
        </div>
    );
}
