import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card } from '@/Components/ui/Card';
import { Head } from '@inertiajs/react';

export default function ImportIndex() {
    return (
        <AuthenticatedLayout header="Import Excel">
            <Head title="Import" />
            <Card title="Import Historical Data">
                <div className="py-10 text-center">
                    <p className="text-4xl">↥</p>
                    <p className="mt-3 text-sm text-slate-500">
                        Excel import (.xlsx / .xlsm / .csv) with preview &amp; validation is coming in the next build step.
                    </p>
                    <p className="mt-1 text-xs text-slate-400">Your existing ACCOUNT WORKBOOK will import here.</p>
                </div>
            </Card>
        </AuthenticatedLayout>
    );
}
