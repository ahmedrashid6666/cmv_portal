import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card } from '@/Components/ui/Card';
import { StatCard } from '@/Components/ui/StatCard';
import { AED } from '@/lib/format';
import { Head, Link } from '@inertiajs/react';
import {
    Area,
    AreaChart,
    Bar,
    BarChart,
    Cell,
    Legend,
    Line,
    LineChart,
    Pie,
    PieChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

const PIE_COLORS = ['#1b9a9b', '#1e3a5f', '#E63946', '#4364b8', '#4ce8eb', '#F15060', '#85eef0', '#7d94ce'];

const alertStyles = {
    info: 'border-primary-200 bg-primary-50 text-primary-800',
    warning: 'border-amber-200 bg-amber-50 text-amber-800',
    danger: 'border-red-200 bg-red-50 text-accent-red-dark',
};

export default function Dashboard({
    stats,
    alerts = [],
    dailyIncomeVsExpense,
    cashFlow,
    paymentBreakdown,
    profitTrend,
    topCustomers,
    expenseCategories,
    recent,
}) {
    return (
        <AuthenticatedLayout header="Dashboard">
            <Head title="Dashboard" />

            {alerts.length > 0 && (
                <div className="mb-6 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
                    {alerts.map((a, i) => (
                        <div key={i} className={'rounded-xl border p-3 text-sm ' + (alertStyles[a.level] || alertStyles.info)}>
                            <p className="font-semibold">{a.title}</p>
                            <p className="mt-0.5 text-xs opacity-90">{a.message}</p>
                        </div>
                    ))}
                </div>
            )}

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
                        <Empty />
                    ) : (
                        <ResponsiveContainer width="100%" height={280}>
                            <PieChart>
                                <Pie data={paymentBreakdown} dataKey="value" nameKey="name" outerRadius={90} label>
                                    {paymentBreakdown.map((_, i) => <Cell key={i} fill={PIE_COLORS[i % PIE_COLORS.length]} />)}
                                </Pie>
                                <Tooltip formatter={(v) => AED(v)} />
                            </PieChart>
                        </ResponsiveContainer>
                    )}
                </Card>
            </div>

            <div className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                <Card title="Monthly Profit Trend (12 months)">
                    <ResponsiveContainer width="100%" height={240}>
                        <LineChart data={profitTrend}>
                            <XAxis dataKey="label" fontSize={11} />
                            <YAxis fontSize={11} />
                            <Tooltip formatter={(v) => AED(v)} />
                            <Line type="monotone" dataKey="profit" name="Net Profit" stroke="#1b9a9b" strokeWidth={2} dot={{ r: 3 }} />
                        </LineChart>
                    </ResponsiveContainer>
                </Card>

                <Card title="Cash Flow (net, 14 days)">
                    <ResponsiveContainer width="100%" height={240}>
                        <AreaChart data={cashFlow}>
                            <XAxis dataKey="date" fontSize={11} />
                            <YAxis fontSize={11} />
                            <Tooltip formatter={(v) => AED(v)} />
                            <Area type="monotone" dataKey="net" name="Net flow" stroke="#1e3a5f" fill="#b7c4e3" />
                        </AreaChart>
                    </ResponsiveContainer>
                </Card>

                <Card title="Top Customers">
                    {topCustomers.length === 0 ? <Empty /> : (
                        <ResponsiveContainer width="100%" height={240}>
                            <BarChart data={topCustomers} layout="vertical" margin={{ left: 20 }}>
                                <XAxis type="number" fontSize={11} />
                                <YAxis type="category" dataKey="label" width={120} fontSize={10} />
                                <Tooltip formatter={(v) => AED(v)} />
                                <Bar dataKey="income" name="Income" fill="#158a8b" radius={[0, 4, 4, 0]} />
                            </BarChart>
                        </ResponsiveContainer>
                    )}
                </Card>

                <Card title="Expense Categories">
                    {expenseCategories.length === 0 ? <Empty label="No expenses yet" /> : (
                        <ResponsiveContainer width="100%" height={240}>
                            <PieChart>
                                <Pie data={expenseCategories} dataKey="value" nameKey="name" outerRadius={80} label>
                                    {expenseCategories.map((_, i) => <Cell key={i} fill={PIE_COLORS[i % PIE_COLORS.length]} />)}
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
                action={<Link href={route('transactions.index')} className="text-xs font-semibold text-primary-600 hover:underline">View all →</Link>}
            >
                {recent.length === 0 ? (
                    <p className="py-8 text-center text-sm text-slate-400">
                        No transactions yet.{' '}
                        <Link href={route('transactions.create')} className="text-primary-600 hover:underline">Add your first transaction</Link>
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

function Empty({ label = 'No transactions yet' }) {
    return <p className="py-16 text-center text-sm text-slate-400">{label}</p>;
}
