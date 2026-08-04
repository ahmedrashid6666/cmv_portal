import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card } from '@/Components/ui/Card';
import Modal from '@/Components/Modal';
import { AED, fmtDate } from '@/lib/format';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const input = 'w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';

const blank = { entry_date: new Date().toISOString().slice(0, 10), item: '', description: '', in_amount: '', out_amount: '' };

export default function PettyCashIndex({ entries, totals }) {
    const role = usePage().props.auth.user.role;
    const canWrite = ['super_admin', 'admin', 'accountant'].includes(role);

    const [modalOpen, setModalOpen] = useState(false);
    const [editingEntry, setEditingEntry] = useState(null);
    const [deleteTarget, setDeleteTarget] = useState(null);
    const [deleting, setDeleting] = useState(false);

    const openAdd = () => { setEditingEntry(null); setModalOpen(true); };
    const openEdit = (e) => { setEditingEntry(e); setModalOpen(true); };
    const closeModal = () => { setModalOpen(false); setEditingEntry(null); };

    const del = (e) => setDeleteTarget(e);
    const confirmDelete = () => {
        setDeleting(true);
        router.delete(route('petty-cash.destroy', deleteTarget.id), {
            preserveScroll: true,
            onFinish: () => { setDeleting(false); setDeleteTarget(null); },
        });
    };

    const balance = (totals.in || 0) - (totals.out || 0);

    const exportUrl = (format) => window.open(route('petty-cash.export') + '?' + new URLSearchParams({ format }).toString(), '_blank');

    return (
        <AuthenticatedLayout header="Petty Cash">
            <Head title="Petty Cash" />

            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <p className="max-w-2xl text-sm text-slate-500">
                    No-invoice cash notes for reference only — these entries do not affect the Cash &amp; Bank Book, Daily Work Sheet balance, or Final Calculation.
                </p>
                <div className="flex shrink-0 gap-2">
                    <button onClick={() => exportUrl('xlsx')} className="rounded-lg border border-emerald-600 px-3 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-50">
                        Excel
                    </button>
                    <button onClick={() => exportUrl('pdf')} className="rounded-lg border border-accent-red px-3 py-2 text-sm font-semibold text-accent-red hover:bg-red-50">
                        PDF
                    </button>
                    {canWrite && (
                        <button onClick={openAdd} className="rounded-lg bg-primary-600 px-5 py-2 text-sm font-semibold text-white shadow hover:bg-primary-700">
                            + Add Entry
                        </button>
                    )}
                </div>
            </div>

            <div className="mb-6 grid grid-cols-1 gap-4 md:grid-cols-3">
                <Summary label="Total In" value={totals.in} accent="text-emerald-700" />
                <Summary label="Total Out" value={totals.out} accent="text-accent-red" />
                <Summary label="Balance" value={balance} accent="text-navy-900" strong />
            </div>

            <Card>
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left text-xs uppercase text-slate-500">
                                <th className="py-2 pr-3">Date</th>
                                <th className="py-2 pr-3">Item</th>
                                <th className="py-2 pr-3">Description</th>
                                <th className="py-2 pr-3 text-right">In</th>
                                <th className="py-2 pr-3 text-right">Out</th>
                                {canWrite && <th className="py-2"></th>}
                            </tr>
                        </thead>
                        <tbody>
                            {entries.data.length === 0 && (
                                <tr><td colSpan="6" className="py-8 text-center text-slate-400">No petty cash entries yet.</td></tr>
                            )}
                            {entries.data.map((e) => (
                                <tr key={e.id} className="border-b last:border-0 hover:bg-slate-200">
                                    <td className="py-2 pr-3 whitespace-nowrap">{fmtDate(e.entry_date)}</td>
                                    <td className="py-2 pr-3 font-medium text-navy-800">{e.item}</td>
                                    <td className="py-2 pr-3 text-slate-500">{e.description || '—'}</td>
                                    <td className="py-2 pr-3 text-right text-emerald-700">{e.in_amount > 0 ? AED(e.in_amount) : '—'}</td>
                                    <td className="py-2 pr-3 text-right text-accent-red">{e.out_amount > 0 ? AED(e.out_amount) : '—'}</td>
                                    {canWrite && (
                                        <td className="py-2 whitespace-nowrap text-right">
                                            <button onClick={() => openEdit(e)} className="text-primary-600 hover:underline">Edit</button>
                                            <button onClick={() => del(e)} className="ml-2 text-accent-red hover:underline">Del</button>
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {entries.last_page > 1 && (
                    <div className="mt-4 flex flex-wrap gap-1">
                        {entries.links.map((l, i) => (
                            <Link key={i} href={l.url || '#'} className={'rounded px-3 py-1 text-sm ' + (l.active ? 'bg-primary-600 text-white' : l.url ? 'text-slate-600 hover:bg-slate-100' : 'text-slate-300')} dangerouslySetInnerHTML={{ __html: l.label }} />
                        ))}
                    </div>
                )}
            </Card>

            {canWrite && (
                <Modal show={modalOpen} onClose={closeModal} maxWidth="md">
                    <div className="p-6">
                        <h2 className="text-lg font-semibold text-navy-900">
                            {editingEntry ? 'Edit Petty Cash Entry' : 'Add Petty Cash Entry'}
                        </h2>
                        <div className="mt-4">
                            <PettyCashForm key={editingEntry?.id ?? 'new'} entry={editingEntry} onDone={closeModal} onCancel={closeModal} />
                        </div>
                    </div>
                </Modal>
            )}

            <Modal show={deleteTarget !== null} onClose={() => setDeleteTarget(null)} maxWidth="sm">
                <div className="p-6">
                    <h2 className="text-lg font-semibold text-navy-900">Delete petty cash entry?</h2>
                    <p className="mt-2 text-sm text-slate-500">
                        {deleteTarget && <>“{deleteTarget.item}” on {fmtDate(deleteTarget.entry_date)} will be permanently removed. This can&rsquo;t be undone.</>}
                    </p>
                    <div className="mt-6 flex justify-end gap-2">
                        <button type="button" onClick={() => setDeleteTarget(null)} className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-navy-700 hover:bg-slate-50">
                            Cancel
                        </button>
                        <button type="button" onClick={confirmDelete} disabled={deleting} className="rounded-lg bg-accent-red px-5 py-2 text-sm font-semibold text-white shadow hover:bg-accent-red-dark disabled:opacity-50">
                            {deleting ? 'Deleting…' : 'Delete'}
                        </button>
                    </div>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}

function PettyCashForm({ entry, onDone, onCancel }) {
    const { data, setData, post, put, processing, errors, reset } = useForm(
        entry
            ? { entry_date: entry.entry_date, item: entry.item, description: entry.description ?? '', in_amount: entry.in_amount || '', out_amount: entry.out_amount || '' }
            : blank,
    );

    const submit = (e) => {
        e.preventDefault();
        const opts = {
            preserveScroll: true,
            onSuccess: () => { reset(); onDone(); },
        };
        if (entry) {
            put(route('petty-cash.update', entry.id), opts);
        } else {
            post(route('petty-cash.store'), opts);
        }
    };

    return (
        <form onSubmit={submit} className="space-y-3">
            <label className="block">
                <span className="mb-1 block text-xs font-medium text-slate-600">Date</span>
                <input type="date" className={input} value={data.entry_date} onChange={(e) => setData('entry_date', e.target.value)} />
                {errors.entry_date && <p className="mt-1 text-xs text-accent-red">{errors.entry_date}</p>}
            </label>
            <label className="block">
                <span className="mb-1 block text-xs font-medium text-slate-600">Item</span>
                <input className={input} value={data.item} onChange={(e) => setData('item', e.target.value)} />
                {errors.item && <p className="mt-1 text-xs text-accent-red">{errors.item}</p>}
            </label>
            <label className="block">
                <span className="mb-1 block text-xs font-medium text-slate-600">Description</span>
                <textarea rows="2" className={input} value={data.description} onChange={(e) => setData('description', e.target.value)} />
            </label>
            <div className="grid grid-cols-2 gap-3">
                <label className="block">
                    <span className="mb-1 block text-xs font-medium text-slate-600">In</span>
                    <input type="number" step="0.01" min="0" className={input + ' text-right'} placeholder="0.00" value={data.in_amount} onChange={(e) => setData('in_amount', e.target.value)} />
                </label>
                <label className="block">
                    <span className="mb-1 block text-xs font-medium text-slate-600">Out</span>
                    <input type="number" step="0.01" min="0" className={input + ' text-right'} placeholder="0.00" value={data.out_amount} onChange={(e) => setData('out_amount', e.target.value)} />
                </label>
            </div>
            <div className="flex justify-end gap-2 pt-2">
                <button type="button" onClick={onCancel} className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-navy-700 hover:bg-slate-50">
                    Cancel
                </button>
                <button disabled={processing} className="rounded-lg bg-primary-600 px-5 py-2 text-sm font-semibold text-white shadow hover:bg-primary-700 disabled:opacity-50">
                    {entry ? 'Update Entry' : 'Add Entry'}
                </button>
            </div>
        </form>
    );
}

function Summary({ label, value, accent, strong }) {
    return (
        <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <p className="text-xs uppercase text-slate-500">{label}</p>
            <p className={'mt-1 font-bold ' + accent + (strong ? ' text-2xl' : ' text-xl')}>{AED(value)}</p>
        </div>
    );
}
