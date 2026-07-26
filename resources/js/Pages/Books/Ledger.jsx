import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card } from '@/Components/ui/Card';
import { BookTable } from '@/Components/ui/BookTable';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

const input = 'rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';

export default function Ledger({ book, filters, customers }) {
    const [f, setF] = useState(filters);

    const apply = (e) => {
        e?.preventDefault();
        router.get(route('books.ledger'), f, { preserveState: true, replace: true });
    };

    return (
        <AuthenticatedLayout header="Customer Ledger">
            <Head title="Customer Ledger" />

            <Card className="mb-4">
                <form onSubmit={apply} className="flex flex-wrap items-end gap-3">
                    <Labeled label="Customer">
                        <select className={input} value={f.customer_id || ''} onChange={(e) => setF({ ...f, customer_id: e.target.value })}>
                            <option value="">Select a customer…</option>
                            {customers.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </select>
                    </Labeled>
                    <Labeled label="From"><input type="date" className={input} value={f.from || ''} onChange={(e) => setF({ ...f, from: e.target.value })} /></Labeled>
                    <Labeled label="To"><input type="date" className={input} value={f.to || ''} onChange={(e) => setF({ ...f, to: e.target.value })} /></Labeled>
                    <button className="rounded-lg bg-navy-700 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800">View</button>
                </form>
            </Card>

            {book.customer ? (
                <>
                    <h2 className="mb-3 text-lg font-semibold text-navy-800">Statement — {book.customer}</h2>
                    <BookTable book={book} inLabel="Debit (Owed)" outLabel="Credit (Paid)" />
                </>
            ) : (
                <Card><p className="py-10 text-center text-slate-400">Select a customer to view their account statement.</p></Card>
            )}
        </AuthenticatedLayout>
    );
}

function Labeled({ label, children }) {
    return (
        <label className="block">
            <span className="mb-1 block text-xs font-medium text-slate-600">{label}</span>
            {children}
        </label>
    );
}
