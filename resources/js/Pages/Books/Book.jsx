import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card } from '@/Components/ui/Card';
import { BookTable } from '@/Components/ui/BookTable';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

const input = 'rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';

export default function Book({ bookKey, title, book, filters }) {
    const [f, setF] = useState(filters);
    const routeName = bookKey === 'cash' ? 'books.cash' : 'books.bank';

    const apply = (e) => {
        e?.preventDefault();
        router.get(route(routeName), f, { preserveState: true, replace: true });
    };
    const exportUrl = (format) => {
        const params = new URLSearchParams({ ...f, format }).toString();
        window.open(route(routeName) + '?' + params, '_blank');
    };

    return (
        <AuthenticatedLayout header={title}>
            <Head title={title} />

            <Card className="mb-4">
                <form onSubmit={apply} className="flex flex-wrap items-end gap-3">
                    <Labeled label="From"><input type="date" className={input} value={f.from || ''} onChange={(e) => setF({ ...f, from: e.target.value })} /></Labeled>
                    <Labeled label="To"><input type="date" className={input} value={f.to || ''} onChange={(e) => setF({ ...f, to: e.target.value })} /></Labeled>
                    <button className="rounded-lg bg-navy-700 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800">Apply</button>
                    <div className="ml-auto flex gap-2">
                        <button type="button" onClick={() => exportUrl('xlsx')} className="rounded-lg border border-emerald-600 px-3 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-50">Excel</button>
                        <button type="button" onClick={() => exportUrl('pdf')} className="rounded-lg border border-accent-red px-3 py-2 text-sm font-semibold text-accent-red hover:bg-red-50">PDF</button>
                        <button type="button" onClick={() => window.print()} className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">Print</button>
                    </div>
                </form>
            </Card>

            <BookTable book={book} />
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
