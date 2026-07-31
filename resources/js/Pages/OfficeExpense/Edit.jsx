import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import OfficeExpenseForm from '@/Components/forms/OfficeExpenseForm';
import { Card } from '@/Components/ui/Card';
import { Head, Link, router } from '@inertiajs/react';

export default function EditOfficeExpense({ officeExpense, expenseCategories, paymentMethods }) {
    return (
        <AuthenticatedLayout header="Edit Office Expense">
            <Head title="Edit Office Expense" />

            <div className="mb-4">
                <Link href={route('operations.index', { type: 'office-expenses' })} className="text-sm font-semibold text-primary-600 hover:underline">
                    ← Back to Office Expenses
                </Link>
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <Card title="Office Expense" className="lg:col-span-1">
                    <OfficeExpenseForm
                        officeExpense={officeExpense}
                        expenseCategories={expenseCategories}
                        paymentMethods={paymentMethods}
                        onDone={() => router.visit(route('operations.index', { type: 'office-expenses' }))}
                    />
                </Card>
                <div className="hidden rounded-xl border border-dashed border-slate-200 p-6 text-sm text-slate-400 lg:col-span-2 lg:block">
                    Update this running cost. Changing the amount or payment method re-adjusts the Cash / Bank balances automatically.
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
