import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card } from '@/Components/ui/Card';
import { AED, fmtDate } from '@/lib/format';
import { Head, Link, router } from '@inertiajs/react';

export default function BinIndex({ trashed }) {
    const restore = (id) => router.put(route('bin.restore', id));
    const purge = (id) => { if (confirm('Permanently delete this transaction? This cannot be undone.')) router.delete(route('bin.force-delete', id)); };

    return (
        <AuthenticatedLayout header="Recycle Bin">
            <Head title="Recycle Bin" />

            <Card title="Deleted Transactions">
                <p className="mb-4 text-xs text-slate-500">Deleted transactions are kept here. Restore them, or permanently remove them.</p>
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left text-xs uppercase text-slate-500">
                                <th className="py-2 pr-4">Deleted</th>
                                <th className="py-2 pr-4">Txn Date</th>
                                <th className="py-2 pr-4">Invoice</th>
                                <th className="py-2 pr-4">Customer</th>
                                <th className="py-2 pr-4">Method</th>
                                <th className="py-2 pr-4 text-right">Grand Total</th>
                                <th className="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {trashed.data.length === 0 && <tr><td colSpan="7" className="py-8 text-center text-slate-400">Recycle bin is empty.</td></tr>}
                            {trashed.data.map((t) => (
                                <tr key={t.id} className="border-b last:border-0 hover:bg-slate-100">
                                    <td className="py-2 pr-4 whitespace-nowrap text-slate-500">{fmtDate(t.deleted_at)}</td>
                                    <td className="py-2 pr-4">{fmtDate(t.transaction_date)}</td>
                                    <td className="py-2 pr-4">{t.invoice_no || '—'}</td>
                                    <td className="py-2 pr-4">{t.customer}</td>
                                    <td className="py-2 pr-4">{t.method}</td>
                                    <td className="py-2 pr-4 text-right font-semibold text-navy-800">{AED(t.grand_total)}</td>
                                    <td className="py-2 whitespace-nowrap text-right">
                                        <button onClick={() => restore(t.id)} className="font-semibold text-primary-600 hover:underline">Restore</button>
                                        <button onClick={() => purge(t.id)} className="ml-3 text-accent-red hover:underline">Delete forever</button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {trashed.last_page > 1 && (
                    <div className="mt-4 flex flex-wrap gap-1">
                        {trashed.links.map((l, i) => (
                            <Link key={i} href={l.url || '#'} className={'rounded px-3 py-1 text-sm ' + (l.active ? 'bg-primary-600 text-white' : l.url ? 'text-slate-600 hover:bg-slate-100' : 'text-slate-300')} dangerouslySetInnerHTML={{ __html: l.label }} />
                        ))}
                    </div>
                )}
            </Card>
        </AuthenticatedLayout>
    );
}
