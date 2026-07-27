import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import TransactionEntryForm from '@/Components/forms/TransactionEntryForm';
import LedgerEntryForm from '@/Components/forms/LedgerEntryForm';
import { Card } from '@/Components/ui/Card';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

const ledgerLabels = (t) => ({
    pending: 'Pending',
    partial: t === 'borrowed' ? 'Partially Returned' : 'Partially Paid',
    returned: 'Returned',
});

export default function AddEntry({ customers, references, vehicles, paymentMethods, expenseCategories, vatRate, customFields, ledgerMetas }) {
    const [type, setType] = useState('transaction');

    const types = [
        { key: 'transaction', title: 'New Transaction', sub: 'Income / Expense shipment entry', icon: '₪' },
        { key: 'daily-credit', title: 'Daily Credit', sub: 'Credit given to a customer', icon: '↗' },
        { key: 'borrowed', title: 'Borrowed Amount', sub: 'Amount borrowed from a person', icon: '↘' },
    ];
    const ledgerMeta = ledgerMetas.find((m) => m.slug === type);

    return (
        <AuthenticatedLayout header="Add Entry">
            <Head title="Add Entry" />

            {/* Transaction type selector */}
            <div className="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-3">
                {types.map((t) => (
                    <button
                        key={t.key}
                        onClick={() => setType(t.key)}
                        className={
                            'flex items-center gap-3 rounded-xl border p-4 text-left transition ' +
                            (type === t.key
                                ? 'border-primary-500 bg-primary-50 shadow-sm ring-1 ring-primary-500'
                                : 'border-slate-200 bg-white hover:border-primary-300 hover:bg-slate-50')
                        }
                    >
                        <span className={'flex h-10 w-10 items-center justify-center rounded-lg text-lg ' + (type === t.key ? 'bg-primary-600 text-white' : 'bg-slate-100 text-navy-700')}>
                            {t.icon}
                        </span>
                        <span>
                            <span className="block text-sm font-semibold text-navy-800">{t.title}</span>
                            <span className="block text-xs text-slate-500">{t.sub}</span>
                        </span>
                    </button>
                ))}
            </div>

            {/* The relevant form */}
            {type === 'transaction' ? (
                <TransactionEntryForm
                    customers={customers}
                    references={references}
                    vehicles={vehicles}
                    paymentMethods={paymentMethods}
                    expenseCategories={expenseCategories}
                    vatRate={vatRate}
                    customFields={customFields}
                    onDone={() => router.visit(route('transactions.index'))}
                />
            ) : (
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <Card title={ledgerMeta.label} className="lg:col-span-1 lg:col-start-1">
                        <LedgerEntryForm
                            key={ledgerMeta.slug}
                            meta={ledgerMeta}
                            labels={ledgerLabels(ledgerMeta.type)}
                            onDone={() => router.visit(route('ledger.index', ledgerMeta.slug))}
                        />
                    </Card>
                    <div className="hidden rounded-xl border border-dashed border-slate-200 p-6 text-sm text-slate-400 lg:col-span-2 lg:block">
                        Enter a {ledgerMeta.label} entry. Balance and status are calculated automatically —
                        after saving you'll be taken to the {ledgerMeta.label} list.
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
