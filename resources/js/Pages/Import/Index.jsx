import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card } from '@/Components/ui/Card';
import { Head, router, useForm } from '@inertiajs/react';

export default function ImportIndex({ preview }) {
    const { setData, post, processing, errors } = useForm({ file: null });

    const upload = (e) => {
        e.preventDefault();
        post(route('import.preview'), { forceFormData: true });
    };
    const commit = () => {
        router.post(route('import.commit'), { token: preview.token });
    };

    return (
        <AuthenticatedLayout header="Import Excel">
            <Head title="Import" />

            <Card title="Import Historical Data" className="mb-6">
                <form onSubmit={upload} className="flex flex-wrap items-end gap-4">
                    <div>
                        <span className="mb-1 block text-xs font-medium text-slate-600">
                            Workbook file (.xlsx / .xlsm / .csv)
                        </span>
                        <input
                            type="file"
                            accept=".xlsx,.xlsm,.csv,.xls"
                            onChange={(e) => setData('file', e.target.files[0])}
                            className="text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-primary-600 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-primary-700"
                        />
                        {errors.file && <span className="mt-1 block text-xs text-accent-red">{errors.file}</span>}
                    </div>
                    <button disabled={processing} className="rounded-lg bg-navy-700 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800 disabled:opacity-50">
                        {processing ? 'Reading…' : 'Preview'}
                    </button>
                </form>
                <p className="mt-3 text-xs text-slate-400">
                    We detect per-day sheets and map your columns automatically. Nothing is saved until you confirm.
                </p>
            </Card>

            {preview && (
                <>
                    <div className="mb-4 grid grid-cols-2 gap-4 md:grid-cols-4">
                        <Stat label="Rows found" value={preview.rowCount} />
                        <Stat label="New customers" value={preview.newCustomers.length} />
                        <Stat label="New references" value={preview.newReferences.length} />
                        <Stat label="New vehicles" value={preview.newVehicles.length} />
                    </div>

                    {preview.errors.length > 0 && (
                        <Card title={`Warnings (${preview.errors.length})`} className="mb-4">
                            <ul className="max-h-40 space-y-1 overflow-y-auto text-xs text-accent-red-dark">
                                {preview.errors.map((e, i) => <li key={i}>• {e}</li>)}
                            </ul>
                        </Card>
                    )}

                    <Card
                        title={`Preview (${preview.sheets.join(', ')})`}
                        action={
                            <button onClick={commit} className="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-primary-700">
                                Confirm &amp; Import {preview.rowCount} rows
                            </button>
                        }
                    >
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left text-xs uppercase text-slate-500">
                                        <th className="py-2 pr-4">Date</th>
                                        <th className="py-2 pr-4">Invoice</th>
                                        <th className="py-2 pr-4">Customer</th>
                                        <th className="py-2 pr-4">Reference</th>
                                        <th className="py-2 pr-4">Vehicle</th>
                                        <th className="py-2 pr-4 text-right">Customs</th>
                                        <th className="py-2 pr-4 text-right">Profit</th>
                                        <th className="py-2 pr-4 text-right">Credit</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {preview.sample.map((r, i) => (
                                        <tr key={i} className="border-b last:border-0">
                                            <td className="py-2 pr-4 whitespace-nowrap">{r.transaction_date}</td>
                                            <td className="py-2 pr-4">{r.invoice_no || '—'}</td>
                                            <td className="py-2 pr-4">{r.customer}</td>
                                            <td className="py-2 pr-4">{r.reference || '—'}</td>
                                            <td className="py-2 pr-4">{r.vehicle || '—'}</td>
                                            <td className="py-2 pr-4 text-right">{r.customs_fees}</td>
                                            <td className="py-2 pr-4 text-right">{r.profit}</td>
                                            <td className="py-2 pr-4 text-right">{r.credit_amount}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        {preview.rowCount > preview.sample.length && (
                            <p className="mt-2 text-xs text-slate-400">Showing first {preview.sample.length} of {preview.rowCount} rows.</p>
                        )}
                    </Card>
                </>
            )}
        </AuthenticatedLayout>
    );
}

function Stat({ label, value }) {
    return (
        <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <p className="text-xs uppercase text-slate-500">{label}</p>
            <p className="mt-1 text-2xl font-bold text-navy-900">{value}</p>
        </div>
    );
}
