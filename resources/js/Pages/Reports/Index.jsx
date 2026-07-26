import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card } from '@/Components/ui/Card';
import { Head, Link } from '@inertiajs/react';

export default function ReportsIndex({ reports }) {
    return (
        <AuthenticatedLayout header="Reports">
            <Head title="Reports" />
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
                {reports.map((r) => (
                    <Link key={r.key} href={route('reports.show', r.key)} className="group">
                        <Card title={r.title} className="h-full transition group-hover:border-primary-400 group-hover:shadow-md">
                            <p className="text-sm text-slate-500">{r.desc}</p>
                            <p className="mt-3 text-xs font-semibold text-primary-600">Open report →</p>
                        </Card>
                    </Link>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
