import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card } from '@/Components/ui/Card';
import { Head } from '@inertiajs/react';

const reports = ['Daily Report', 'Monthly Report', 'Customer-wise Report', 'Outstanding Credit Report'];

export default function ReportsIndex() {
    return (
        <AuthenticatedLayout header="Reports">
            <Head title="Reports" />
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
                {reports.map((r) => (
                    <Card key={r} title={r}>
                        <p className="text-sm text-slate-400">Filters, totals, chart and PDF/Excel export — coming in the next build step.</p>
                    </Card>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
