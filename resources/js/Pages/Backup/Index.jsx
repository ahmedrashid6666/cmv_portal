import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card } from '@/Components/ui/Card';
import { Head, router, useForm } from '@inertiajs/react';

export default function BackupIndex({ backups }) {
    const { post, processing, errors } = useForm({});
    const create = () => post(route('backup.create'));
    const download = (name) => window.open(route('backup.download', name), '_blank');
    const remove = (name) => { if (confirm('Delete this backup file?')) router.delete(route('backup.destroy', name)); };

    return (
        <AuthenticatedLayout header="Database Backup">
            <Head title="Backup" />

            <Card title="Backups" action={
                <button onClick={create} disabled={processing} className="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-primary-700 disabled:opacity-50">
                    {processing ? 'Creating…' : '+ Create Backup Now'}
                </button>
            }>
                <p className="mb-4 text-xs text-slate-500">
                    Creates a full MySQL dump you can download and keep safe. For automatic daily backups on Hostinger,
                    schedule a <code>mysqldump</code> cron (see the deployment guide).
                </p>
                {errors.backup && <p className="mb-3 rounded-lg bg-red-50 p-3 text-sm text-accent-red-dark">{errors.backup}</p>}

                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left text-xs uppercase text-slate-500">
                                <th className="py-2 pr-4">File</th>
                                <th className="py-2 pr-4">Created</th>
                                <th className="py-2 pr-4">Size</th>
                                <th className="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {backups.length === 0 && <tr><td colSpan="4" className="py-8 text-center text-slate-400">No backups yet.</td></tr>}
                            {backups.map((b) => (
                                <tr key={b.name} className="border-b last:border-0 hover:bg-slate-200">
                                    <td className="py-2 pr-4 font-mono text-xs text-navy-800">{b.name}</td>
                                    <td className="py-2 pr-4 text-slate-500">{b.at}</td>
                                    <td className="py-2 pr-4">{b.size}</td>
                                    <td className="py-2 whitespace-nowrap text-right">
                                        <button onClick={() => download(b.name)} className="font-semibold text-primary-600 hover:underline">Download</button>
                                        <button onClick={() => remove(b.name)} className="ml-3 text-accent-red hover:underline">Delete</button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </Card>
        </AuthenticatedLayout>
    );
}
