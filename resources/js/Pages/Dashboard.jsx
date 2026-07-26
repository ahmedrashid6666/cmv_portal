import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card } from '@/Components/ui/Card';
import { StatCard } from '@/Components/ui/StatCard';
import { AED } from '@/lib/format';
import { Head, Link } from '@inertiajs/react';
import {
    Bar,
    BarChart,
    Cell,
    Legend,
    Pie,
    PieChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

const PIE_COLORS = ['#1b9a9b', '#1e3a5f', '#E63946', '#4364b8', '#4ce8eb', '#F15060'];

export default function Dashboard({ stats, dailyIncomeVsExpense, paymentBreakdown, recent }) {
    return (
        <AuthenticatedLayout header="Dashboard">
            <Head title="Dashboard" />

            <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard label="Today's Income" value={stats.todaysIncome} accent="primary" icon="↑" />
                <StatCard label="Today's Expenses" value={stats.todaysExpenses} accent="red" icon="↓" />
                <StatCard label="Cash Balance" value={stats.cashBalance} accent="green" icon="₵" />
                <StatCard label="Bank Balance" value={stats.bankBalance} accent="navy" icon="⛁" />
                <StatCard label="Credit Outstanding" value={stats.creditBalance} accent="red" icon="◔" />
                <StatCard label="Total Profit" value={stats.totalProfit} accent="primary" icon="★" />
                <StatCard label="Monthly Income" value={stats.monthlyIncome} accent="navy" icon="▤" />
                <StatCard label="Monthly Expenses" value={stats.monthlyExpenses} accent="red" icon="▤" />
                <StatCard label="Total Customers" value={stats.totalCustomers} money={false} accent="primary" icon="◈" />
                <StatCard label="Pending Credits" value={stats.pendingCredits} money={false} accent="red" icon="!" />
            </div>

            <div className="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
                <Card title="Daily Income vs Expense (14 days)" className="lg:col-span-2">
                    <ResponsiveContainer width="100%" height={280}>
                        <BarChart data={dailyIncomeVsExpense}>
                            <XAxis dataKey="date" fontSize={11} tickMargin={8} />
                            <YAxis fontSize={11} />
                            <Tooltip formatter={(v) => AED(v)} />
                            <Legend />
                            <Bar dataKey="income" name="Income" fill="#1b9a9b" radius={[4, 4, 0, 0]} />
                            <Bar dataKey="expense" name="Expense" fill="#E63946" radius={[4, 4, 0, 0]} />
                        </BarChart>
                    </ResponsiveContainer>
                </Card>

                <Card title="Payment Method Breakdown">
                    {paymentBreakdown.length === 0 ? (
                        <p className="py-16 text-center text-sm text-slate-400">No transactions yet</p>
                    ) : (
                        <ResponsiveContainer width="100%" height={280}>
                            <PieChart>
                                <Pie data={paymentBreakdown} dataKey="value" nameKey="name" outerRadius={90} label>
                                    {paymentBreakdown.map((_, i) => (
                                        <Cell key={i} fill={PIE_COLORS[i % PIE_COLORS.length]} />
                                    ))}
                                </Pie>
                                <Tooltip formatter={(v) => AED(v)} />
                            </PieChart>
                        </ResponsiveContainer>
                    )}
                </Card>
            </div>

            <Card
                title="Recent Transactions"
                className="mt-6"
                action={
                    <Link href={route('transactions.index')} className="text-xs font-semibold text-primary-600 hover:underline">
                        View all →
                    </Link>
                }
            >
                {recent.length === 0 ? (
                    <p className="py-8 text-center text-sm text-slate-400">
                        No transactions yet.{' '}
                        <Link href={route('transactions.create')} className="text-primary-600 hover:underline">
                            Add your first transaction
                        </Link>
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left text-xs uppercase text-slate-500">
                                    <th className="py-2 pr-4">Date</th>
                                    <th className="py-2 pr-4">Invoice</th>
                                    <th className="py-2 pr-4">Customer</th>
                                    <th className="py-2 pr-4">Method</th>
                                    <th className="py-2 pr-4 text-right">Grand Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                {recent.map((t) => (
                                    <tr key={t.id} className="border-b last:border-0">
                                        <td className="py-2 pr-4">{t.transaction_date}</td>
                                        <td className="py-2 pr-4">{t.invoice_no || '—'}</td>
                                        <td className="py-2 pr-4">{t.customer?.name}</td>
                                        <td className="py-2 pr-4">{t.payment_method?.name}</td>
                                        <td className="py-2 pr-4 text-right font-semibold text-navy-800">{AED(t.grand_total)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>
        </AuthenticatedLayout>
    );
}
