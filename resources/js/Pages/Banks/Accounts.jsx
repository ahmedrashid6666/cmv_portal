import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card } from '@/Components/ui/Card';
import { money } from '@/lib/format';
import { todayLocalISO } from '@/lib/date';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const input = 'rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';
const today = todayLocalISO;

export default function BankAccounts({ banks, totals, combinedBankBalance }) {
    const role = usePage().props.auth.user.role;
    const canWrite = ['super_admin', 'admin', 'accountant'].includes(role);
    const unassigned = Math.round((combinedBankBalance - totals.balance) * 100) / 100;

    const [openBank, setOpenBank] = useState(null); // the bank whose In/Out dialog is open

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
                                <tr key={b.id} className="border-b last:border-0 hover:bg-slate-100">
                                    <td className="py-2 pr-4 font-medium text-navy-800">
                                        {b.name}
                                        {b.is_customs && <span className="ml-2 rounded-full bg-primary-100 px-2 py-0.5 text-[10px] font-semibold text-primary-700">CUSTOMS / CDR</span>}
                                    </td>
                                    <td className="py-2 pr-4 text-slate-500">{b.account_no || '—'}</td>
                                    <td className="py-2 pr-4 text-right tabular-nums">{money(b.opening, 'AED')}</td>
                                    <td className="py-2 pr-4 text-right tabular-nums text-accent-red">{b.customs_paid ? '−' + money(b.customs_paid, 'AED') : '—'}</td>
                                    <td className="py-2 pr-4 text-right tabular-nums text-accent-red">{b.gov_paid ? '−' + money(b.gov_paid, 'AED') : '—'}</td>
                                    <td className={'py-2 pr-4 text-right font-semibold tabular-nums ' + (b.balance < 0 ? 'text-accent-red' : 'text-navy-900')}>{money(b.balance, 'AED')}</td>
                                    <td className="py-2 text-right whitespace-nowrap">
                                        {canWrite && (
                                            <button onClick={() => setOpenBank(b)} className="mr-3 font-semibold text-navy-700 hover:underline">In / Out</button>
                                        )}
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

            {openBank && <InOutDialog bank={banks.find((b) => b.id === openBank.id) || openBank} onClose={() => setOpenBank(null)} />}
        </AuthenticatedLayout>
    );
}

function InOutDialog({ bank, onClose }) {
    const entries = bank.entries || [];
    const [editingEntry, setEditingEntry] = useState(null);
    const form = useForm({ entry_date: today(), item: '', description: '', in: '', out: '' });

    const blankForm = { entry_date: today(), item: '', description: '', in: '', out: '' };

    const startEdit = (e) => {
        setEditingEntry(e);
        form.setData({
            entry_date: e.date,
            item: e.item,
            description: e.description || '',
            in: e.direction === 'in' ? e.amount : '',
            out: e.direction === 'out' ? e.amount : '',
        });
    };

    const cancelEdit = () => {
        setEditingEntry(null);
        form.setData(blankForm);
        form.clearErrors();
    };

    const submit = (e) => {
        e.preventDefault();
        if (editingEntry) {
            form.put(route('bank-accounts.entries.update', editingEntry.id), {
                preserveState: true,
                preserveScroll: true,
                onSuccess: () => { setEditingEntry(null); form.setData(blankForm); },
            });
        } else {
            form.post(route('bank-accounts.entries.store', bank.id), {
                preserveState: true,
                preserveScroll: true,
                onSuccess: () => form.setData(blankForm),
            });
        }
    };

    const remove = (id) => {
        if (!confirm('Remove this entry?')) return;
        if (editingEntry?.id === id) cancelEdit();
        router.delete(route('bank-accounts.entries.destroy', id), { preserveState: true, preserveScroll: true });
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
            <div className="w-full max-w-3xl rounded-xl bg-white p-6 shadow-xl">
                <div className="mb-3 flex items-start justify-between">
                    <div>
                        <h3 className="text-lg font-semibold text-navy-800">In / Out — {bank.name}</h3>
                        <p className="text-xs text-slate-500">Balance <span className="font-semibold text-navy-800">{money(bank.balance, 'AED')}</span></p>
                    </div>
                    <button onClick={onClose} className="text-slate-400 hover:text-slate-600">✕</button>
                </div>

                {/* Add / edit form */}
                {editingEntry && (
                    <p className="mb-2 text-xs font-semibold text-primary-700">Editing entry — {editingEntry.item}</p>
                )}
                <form onSubmit={submit} className={'grid grid-cols-1 gap-2 rounded-lg p-3 sm:grid-cols-12 ' + (editingEntry ? 'bg-primary-50' : 'bg-slate-50')}>
                    <label className="sm:col-span-2">
                        <span className="mb-1 block text-[11px] font-medium text-slate-500">Date</span>
                        <input type="date" className={input + ' w-full'} value={form.data.entry_date} onChange={(e) => form.setData('entry_date', e.target.value)} required />
                    </label>
                    <label className="sm:col-span-3">
                        <span className="mb-1 block text-[11px] font-medium text-slate-500">Item</span>
                        <input className={input + ' w-full'} placeholder="e.g. Cash deposit" value={form.data.item} onChange={(e) => form.setData('item', e.target.value)} required />
                    </label>
                    <label className="sm:col-span-3">
                        <span className="mb-1 block text-[11px] font-medium text-slate-500">Description</span>
                        <input className={input + ' w-full'} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                    </label>
                    <label className="sm:col-span-2">
                        <span className="mb-1 block text-[11px] font-medium text-emerald-700">In</span>
                        <input type="number" step="0.01" min="0" className={input + ' w-full'} value={form.data.in} onChange={(e) => form.setData({ ...form.data, in: e.target.value, out: '' })} />
                    </label>
                    <label className="sm:col-span-2">
                        <span className="mb-1 block text-[11px] font-medium text-accent-red">Out</span>
                        <input type="number" step="0.01" min="0" className={input + ' w-full'} value={form.data.out} onChange={(e) => form.setData({ ...form.data, out: e.target.value, in: '' })} />
                    </label>
                    <div className="flex items-end gap-3 sm:col-span-12">
                        <button disabled={form.processing} className="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50">
                            {editingEntry ? 'Update entry' : 'Add entry'}
                        </button>
                        {editingEntry && (
                            <button type="button" onClick={cancelEdit} className="text-sm font-semibold text-slate-500 hover:underline">Cancel</button>
                        )}
                        {form.errors.amount && <span className="ml-3 self-center text-xs text-accent-red">{form.errors.amount}</span>}
                        {form.errors.item && <span className="ml-3 self-center text-xs text-accent-red">{form.errors.item}</span>}
                    </div>
                </form>

                {/* List */}
                <div className="mt-4 max-h-72 overflow-y-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left text-xs uppercase text-slate-500">
                                <th className="py-2 pr-3">Date</th>
                                <th className="py-2 pr-3">Item</th>
                                <th className="py-2 pr-3">Description</th>
                                <th className="py-2 pr-3 text-right">In</th>
                                <th className="py-2 pr-3 text-right">Out</th>
                                <th className="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {entries.length === 0 && <tr><td colSpan="6" className="py-6 text-center text-slate-400">No entries yet.</td></tr>}
                            {entries.map((e) => (
                                <tr key={e.id} className={'border-b last:border-0 ' + (editingEntry?.id === e.id ? 'bg-primary-50' : '')}>
                                    <td className="py-2 pr-3 whitespace-nowrap text-slate-500">{e.date}</td>
                                    <td className="py-2 pr-3 font-medium text-navy-800">{e.item}</td>
                                    <td className="py-2 pr-3 text-slate-500">{e.description || '—'}</td>
                                    <td className="py-2 pr-3 text-right tabular-nums text-emerald-700">{e.direction === 'in' ? money(e.amount, 'AED') : '—'}</td>
                                    <td className="py-2 pr-3 text-right tabular-nums text-accent-red">{e.direction === 'out' ? money(e.amount, 'AED') : '—'}</td>
                                    <td className="py-2 text-right whitespace-nowrap">
                                        <button onClick={() => startEdit(e)} className="mr-3 text-xs text-primary-600 hover:underline">Edit</button>
                                        <button onClick={() => remove(e.id)} className="text-xs text-accent-red hover:underline">Delete</button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        {entries.length > 0 && (
                            <tfoot>
                                <tr className="border-t-2 font-semibold text-navy-900">
                                    <td className="py-2 pr-3" colSpan="3">Total</td>
                                    <td className="py-2 pr-3 text-right tabular-nums text-emerald-700">{money(bank.total_in, 'AED')}</td>
                                    <td className="py-2 pr-3 text-right tabular-nums text-accent-red">{money(bank.total_out, 'AED')}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        )}
                    </table>
                </div>

                <div className="mt-4 flex justify-end">
                    <button onClick={onClose} className="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">Close</button>
                </div>
            </div>
        </div>
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
