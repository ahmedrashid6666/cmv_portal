import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card } from '@/Components/ui/Card';
import { fmtDate } from '@/lib/format';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

const input = 'rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';

// Values on an activity diff are usually scalars, but an array-cast model
// attribute can still slip through as an object/array — String() on those
// renders "[object Object]", so format them as readable JSON instead.
const fmtChange = (v) => {
    if (v === null || v === undefined || v === '') return '—';
    if (typeof v !== 'object') return String(v);

    return Array.isArray(v) ? v.join(', ') : JSON.stringify(v);
};

const actionStyle = {
    created: 'bg-emerald-100 text-emerald-800',
    updated: 'bg-amber-100 text-amber-800',
    deleted: 'bg-red-100 text-accent-red-dark',
    restored: 'bg-primary-100 text-primary-800',
    force_deleted: 'bg-slate-200 text-slate-700',
};

export default function ActivityIndex({ logs, filters, users, models, actions }) {
    const [f, setF] = useState(filters);
    const apply = (e) => { e?.preventDefault(); router.get(route('activity.index'), f, { preserveState: true, replace: true }); };
    const reset = () => { setF({}); router.get(route('activity.index')); };

    return (
        <AuthenticatedLayout header="Activity Log">
            <Head title="Activity Log" />

            <Card className="mb-4">
                <form onSubmit={apply} className="grid grid-cols-2 gap-3 md:grid-cols-6">
                    <select className={input} value={f.action || ''} onChange={(e) => setF({ ...f, action: e.target.value })}>
                        <option value="">All actions</option>
                        {actions.map((a) => <option key={a} value={a}>{a}</option>)}
                    </select>
                    <select className={input} value={f.model || ''} onChange={(e) => setF({ ...f, model: e.target.value })}>
                        <option value="">All types</option>
                        {models.map((m) => <option key={m} value={m}>{m}</option>)}
                    </select>
                    <select className={input} value={f.user_id || ''} onChange={(e) => setF({ ...f, user_id: e.target.value })}>
                        <option value="">All users</option>
                        {users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                    </select>
                    <input type="date" className={input} value={f.from || ''} onChange={(e) => setF({ ...f, from: e.target.value })} />
                    <input type="date" className={input} value={f.to || ''} onChange={(e) => setF({ ...f, to: e.target.value })} />
                    <div className="flex gap-2">
                        <button className="flex-1 rounded-lg bg-navy-700 px-3 py-2 text-sm font-semibold text-white hover:bg-navy-800">Filter</button>
                        <button type="button" onClick={reset} className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50">Reset</button>
                    </div>
                </form>
            </Card>

            <Card>
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left text-xs uppercase text-slate-500">
                                <th className="py-2 pr-4">When</th>
                                <th className="py-2 pr-4">User</th>
                                <th className="py-2 pr-4">Action</th>
                                <th className="py-2 pr-4">Type</th>
                                <th className="py-2 pr-4">Record</th>
                                <th className="py-2 pr-4">Changes</th>
                            </tr>
                        </thead>
                        <tbody>
                            {logs.data.length === 0 && <tr><td colSpan="6" className="py-8 text-center text-slate-400">No activity yet.</td></tr>}
                            {logs.data.map((l) => (
                                <tr key={l.id} className="border-b align-top last:border-0 hover:bg-slate-200">
                                    <td className="py-2 pr-4 whitespace-nowrap text-slate-500">{fmtDate(l.at)}</td>
                                    <td className="py-2 pr-4">{l.user}</td>
                                    <td className="py-2 pr-4"><span className={'rounded-full px-2 py-0.5 text-xs font-semibold ' + (actionStyle[l.action] || '')}>{l.action}</span></td>
                                    <td className="py-2 pr-4">{l.model}</td>
                                    <td className="py-2 pr-4 font-medium text-navy-800">{l.label}</td>
                                    <td className="py-2 pr-4">
                                        {l.changes ? (
                                            <ul className="space-y-0.5 text-xs">
                                                {Object.entries(l.changes).map(([field, [oldV, newV]]) => (
                                                    <li key={field}>
                                                        <span className="text-slate-500">{field}:</span>{' '}
                                                        <span className="text-accent-red line-through">{fmtChange(oldV)}</span>{' → '}
                                                        <span className="text-emerald-700">{fmtChange(newV)}</span>
                                                    </li>
                                                ))}
                                            </ul>
                                        ) : <span className="text-slate-300">—</span>}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {logs.last_page > 1 && (
                    <div className="mt-4 flex flex-wrap gap-1">
                        {logs.links.map((l, i) => (
                            <Link key={i} href={l.url || '#'} className={'rounded px-3 py-1 text-sm ' + (l.active ? 'bg-primary-600 text-white' : l.url ? 'text-slate-600 hover:bg-slate-100' : 'text-slate-300')} dangerouslySetInnerHTML={{ __html: l.label }} />
                        ))}
                    </div>
                )}
            </Card>
        </AuthenticatedLayout>
    );
}
