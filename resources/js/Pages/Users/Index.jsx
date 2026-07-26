import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card } from '@/Components/ui/Card';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const input = 'w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';

const roleBadge = {
    super_admin: 'bg-primary-100 text-primary-800',
    admin: 'bg-navy-100 text-navy-800',
    accountant: 'bg-emerald-100 text-emerald-800',
    read_only: 'bg-slate-100 text-slate-700',
};

export default function UsersIndex({ users, roles }) {
    const me = usePage().props.auth.user;
    const [editing, setEditing] = useState(null);

    const { data, setData, post, put, processing, errors, reset } = useForm({
        name: '', email: '', password: '', role: 'accountant', is_active: true,
    });

    const openNew = () => { setEditing(null); reset(); setData({ name: '', email: '', password: '', role: 'accountant', is_active: true }); };
    const openEdit = (u) => { setEditing(u.id); setData({ name: u.name, email: u.email, password: '', role: u.role, is_active: u.is_active }); };

    const submit = (e) => {
        e.preventDefault();
        const opts = { onSuccess: openNew };
        editing ? put(route('users.update', editing), opts) : post(route('users.store'), opts);
    };
    const del = (u) => { if (confirm(`Delete ${u.name}?`)) router.delete(route('users.destroy', u.id)); };

    return (
        <AuthenticatedLayout header="User Management">
            <Head title="Users" />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <Card title={`Users (${users.length})`} className="lg:col-span-2">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left text-xs uppercase text-slate-500">
                                    <th className="py-2 pr-4">Name</th>
                                    <th className="py-2 pr-4">Email</th>
                                    <th className="py-2 pr-4">Role</th>
                                    <th className="py-2 pr-4">Status</th>
                                    <th className="py-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {users.map((u) => (
                                    <tr key={u.id} className="border-b last:border-0 hover:bg-slate-50">
                                        <td className="py-2 pr-4 font-medium text-navy-800">
                                            {u.name} {u.id === me.id && <span className="text-xs text-slate-400">(you)</span>}
                                        </td>
                                        <td className="py-2 pr-4">{u.email}</td>
                                        <td className="py-2 pr-4">
                                            <span className={'rounded-full px-2 py-0.5 text-xs font-semibold ' + (roleBadge[u.role] || '')}>
                                                {u.role.replace('_', ' ')}
                                            </span>
                                        </td>
                                        <td className="py-2 pr-4">
                                            {u.is_active
                                                ? <span className="text-emerald-600">● Active</span>
                                                : <span className="text-slate-400">● Inactive</span>}
                                        </td>
                                        <td className="py-2 whitespace-nowrap text-right">
                                            <button onClick={() => openEdit(u)} className="text-primary-600 hover:underline">Edit</button>
                                            {u.id !== me.id && <button onClick={() => del(u)} className="ml-3 text-accent-red hover:underline">Delete</button>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>

                <Card title={editing ? 'Edit User' : 'Add User'} action={editing && <button onClick={openNew} className="text-xs text-slate-500 hover:underline">Cancel</button>}>
                    <form onSubmit={submit} className="space-y-3">
                        <Field label="Full Name" error={errors.name}>
                            <input className={input} value={data.name} onChange={(e) => setData('name', e.target.value)} />
                        </Field>
                        <Field label="Email" error={errors.email}>
                            <input type="email" className={input} value={data.email} onChange={(e) => setData('email', e.target.value)} />
                        </Field>
                        <Field label={editing ? 'New Password (leave blank to keep)' : 'Password'} error={errors.password}>
                            <input type="password" className={input} value={data.password} onChange={(e) => setData('password', e.target.value)} />
                        </Field>
                        <Field label="Role" error={errors.role}>
                            <select className={input} value={data.role} onChange={(e) => setData('role', e.target.value)} disabled={editing === me.id}>
                                {roles.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
                            </select>
                        </Field>
                        <label className="flex items-center gap-2 text-sm text-slate-600">
                            <input type="checkbox" className="rounded border-slate-300 text-primary-600 focus:ring-primary-500" checked={data.is_active} onChange={(e) => setData('is_active', e.target.checked)} disabled={editing === me.id} />
                            Active (can log in)
                        </label>
                        {editing === me.id && <p className="text-xs text-slate-400">You can't change your own role or deactivate yourself.</p>}
                        <button disabled={processing} className="w-full rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-primary-700 disabled:opacity-50">
                            {editing ? 'Update User' : 'Create User'}
                        </button>
                    </form>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}

function Field({ label, error, children }) {
    return (
        <label className="block">
            <span className="mb-1 block text-xs font-medium text-slate-600">{label}</span>
            {children}
            {error && <span className="mt-1 block text-xs text-accent-red">{error}</span>}
        </label>
    );
}
