import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card } from '@/Components/ui/Card';
import { BookTable } from '@/Components/ui/BookTable';
import { money } from '@/lib/format';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const input = 'rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';

export default function CashBank({ filters, show, cash, bank, cashOpening }) {
    const [f, setF] = useState(filters);
    const [showCash, setShowCash] = useState(show.includes('cash'));
    const [showBank, setShowBank] = useState(show.includes('bank'));
    const isSuperAdmin = usePage().props.auth.user?.role === 'super_admin';

    const apply = (e) => {
        e?.preventDefault();
        const sel = [showCash && 'cash', showBank && 'bank'].filter(Boolean);
        router.get(route('books.cashbank'), { ...f, show: sel.join(',') || 'none' }, { preserveState: true, replace: true });
    };
    const exportUrl = (book, format) => {
        const params = new URLSearchParams({ ...f, format }).toString();
        window.open(route(book === 'cash' ? 'books.cash' : 'books.bank') + '?' + params, '_blank');
    };

    return (
        <AuthenticatedLayout header="Cash & Bank Book">
            <Head title="Cash & Bank Book" />

            <Card className="mb-4">
                <form onSubmit={apply} className="flex flex-wrap items-end gap-4">
                    <Labeled label="From"><input type="date" className={input} value={f.from || ''} onChange={(e) => setF({ ...f, from: e.target.value })} /></Labeled>
                    <Labeled label="To"><input type="date" className={input} value={f.to || ''} onChange={(e) => setF({ ...f, to: e.target.value })} /></Labeled>

                    <div className="flex items-end gap-4 pb-1">
                        <label className="flex items-center gap-2 text-sm font-medium text-navy-800">
                            <input type="checkbox" className="rounded border-slate-300 text-primary-600 focus:ring-primary-500" checked={showCash} onChange={(e) => setShowCash(e.target.checked)} />
                            Cash Book
                        </label>
                        <label className="flex items-center gap-2 text-sm font-medium text-navy-800">
                            <input type="checkbox" className="rounded border-slate-300 text-primary-600 focus:ring-primary-500" checked={showBank} onChange={(e) => setShowBank(e.target.checked)} />
                            Bank Book
                        </label>
                    </div>

                    <button className="rounded-lg bg-navy-700 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800">Apply</button>
                </form>
            </Card>

            {!cash && !bank && (
                <Card><p className="py-10 text-center text-slate-400">Select at least one book (Cash or Bank) and click Apply.</p></Card>
            )}

            {cash && (
                <section className="mb-8">
                    <div className="mb-3 flex items-center justify-between">
                        <h2 className="text-lg font-semibold text-navy-800">₵ Cash Book</h2>
                        <div className="flex items-center gap-2">
                            {isSuperAdmin && <OpeningBalanceEditor current={cashOpening} />}
                            <button onClick={() => exportUrl('cash', 'xlsx')} className="rounded-lg border border-emerald-600 px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-50">Excel</button>
                            <button onClick={() => exportUrl('cash', 'pdf')} className="rounded-lg border border-accent-red px-3 py-1.5 text-xs font-semibold text-accent-red hover:bg-red-50">PDF</button>
                            <button onClick={() => window.print()} className="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50">Print</button>
                        </div>
                    </div>
                    <BookTable book={cash} />
                </section>
            )}

            {bank && (
                <section>
                    <div className="mb-3 flex items-center justify-between">
                        <h2 className="text-lg font-semibold text-navy-800">⛁ Bank Book</h2>
                        <div className="flex gap-2">
                            <button onClick={() => exportUrl('bank', 'xlsx')} className="rounded-lg border border-emerald-600 px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-50">Excel</button>
                            <button onClick={() => exportUrl('bank', 'pdf')} className="rounded-lg border border-accent-red px-3 py-1.5 text-xs font-semibold text-accent-red hover:bg-red-50">PDF</button>
                            <button onClick={() => window.print()} className="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50">Print</button>
                        </div>
                    </div>
                    <BookTable book={bank} />
                </section>
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

/** Inline setter for the cash book's opening balance (super-admin only). */
function OpeningBalanceEditor({ current }) {
    const [open, setOpen] = useState(false);
    const { data, setData, put, processing } = useForm({ cash_opening_balance: current ?? 0 });

    const save = (e) => {
        e.preventDefault();
        put(route('books.cash-opening'), {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    };

    if (!open) {
        return (
            <button
                onClick={() => { setData('cash_opening_balance', current ?? 0); setOpen(true); }}
                className="rounded-lg border border-primary-600 px-3 py-1.5 text-xs font-semibold text-primary-700 hover:bg-primary-50"
                title="Set the cash book's starting balance"
            >
                Opening: {money(current || 0, 'AED')}
            </button>
        );
    }

    return (
        <form onSubmit={save} className="flex items-center gap-1.5">
            <input
                type="number"
                step="0.01"
                autoFocus
                className={input + ' w-32 px-2 py-1.5 text-xs'}
                value={data.cash_opening_balance}
                onChange={(e) => setData('cash_opening_balance', e.target.value)}
            />
            <button type="submit" disabled={processing} className="rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-primary-700 disabled:opacity-50">Save</button>
            <button type="button" onClick={() => setOpen(false)} className="rounded-lg border border-slate-300 px-2 py-1.5 text-xs text-slate-500 hover:bg-slate-50">✕</button>
        </form>
    );
}
