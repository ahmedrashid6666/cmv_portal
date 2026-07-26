import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card } from '@/Components/ui/Card';
import { Head, useForm } from '@inertiajs/react';

const input = 'w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';

export default function SettingsIndex({ company, database, flash }) {
    const c = useForm({ ...company });
    const d = useForm({
        host: database.host, port: database.port, database: database.database,
        username: database.username, password: '',
    });

    const saveCompany = (e) => { e.preventDefault(); c.put(route('settings.company')); };
    const testDb = () => d.post(route('settings.database.test'), { preserveScroll: true });
    const saveDb = (e) => { e.preventDefault(); d.put(route('settings.database'), { preserveScroll: true }); };

    return (
        <AuthenticatedLayout header="Settings">
            <Head title="Settings" />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                {/* Company */}
                <Card title="Company & Accounting">
                    <form onSubmit={saveCompany} className="space-y-3">
                        <Field label="Company Name" error={c.errors.company_name}>
                            <input className={input} value={c.data.company_name} onChange={(e) => c.setData('company_name', e.target.value)} />
                        </Field>
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Currency" error={c.errors.currency}>
                                <input className={input} value={c.data.currency} onChange={(e) => c.setData('currency', e.target.value)} />
                            </Field>
                            <Field label="VAT Rate (%)" error={c.errors.vat_rate}>
                                <input type="number" step="0.01" className={input} value={c.data.vat_rate} onChange={(e) => c.setData('vat_rate', e.target.value)} />
                            </Field>
                        </div>
                        <Field label="Cash Opening Balance" error={c.errors.cash_opening_balance}>
                            <input type="number" step="0.01" className={input} value={c.data.cash_opening_balance} onChange={(e) => c.setData('cash_opening_balance', e.target.value)} />
                        </Field>
                        <button disabled={c.processing} className="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-primary-700 disabled:opacity-50">
                            Save Company Settings
                        </button>
                    </form>
                </Card>

                {/* Database */}
                <Card title="Database Connection">
                    <div className="mb-3 rounded-lg bg-amber-50 p-3 text-xs text-amber-800">
                        ⚠️ Advanced. Wrong values can take the app offline. The connection is <b>tested before saving</b>,
                        and changes are written to your <code>.env</code> file. Current driver: <b>{database.connection}</b>.
                    </div>
                    <form onSubmit={saveDb} className="space-y-3">
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Host" error={d.errors.host}>
                                <input className={input} value={d.data.host} onChange={(e) => d.setData('host', e.target.value)} />
                            </Field>
                            <Field label="Port" error={d.errors.port}>
                                <input className={input} value={d.data.port} onChange={(e) => d.setData('port', e.target.value)} />
                            </Field>
                        </div>
                        <Field label="Database Name" error={d.errors.database}>
                            <input className={input} value={d.data.database} onChange={(e) => d.setData('database', e.target.value)} />
                        </Field>
                        <Field label="Username" error={d.errors.username}>
                            <input className={input} value={d.data.username} onChange={(e) => d.setData('username', e.target.value)} />
                        </Field>
                        <Field label={'Password ' + (database.password_set ? '(leave blank to keep current)' : '')} error={d.errors.database || d.errors.password}>
                            <input type="password" className={input} value={d.data.password} onChange={(e) => d.setData('password', e.target.value)} placeholder={database.password_set ? '••••••••' : ''} />
                        </Field>
                        {d.errors.database && <p className="text-xs text-accent-red">{d.errors.database}</p>}
                        <div className="flex gap-2">
                            <button type="button" onClick={testDb} disabled={d.processing} className="rounded-lg border border-navy-600 px-4 py-2 text-sm font-semibold text-navy-700 hover:bg-navy-50 disabled:opacity-50">
                                Test Connection
                            </button>
                            <button disabled={d.processing} className="rounded-lg bg-navy-700 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800 disabled:opacity-50">
                                Test &amp; Save
                            </button>
                        </div>
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
