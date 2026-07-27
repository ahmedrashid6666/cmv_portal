import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card } from '@/Components/ui/Card';
import { fmtDate } from '@/lib/format';
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
                        <Stat label="New — will import" value={preview.newCount} accent="text-emerald-700" />
                        <Stat label="Duplicates — will skip" value={preview.duplicateCount} accent={preview.duplicateCount > 0 ? 'text-amber-600' : 'text-navy-900'} />
                        <Stat label="New customers" value={preview.newCustomers.length} />
                    </div>

                    <p className="mb-4 text-xs text-slate-500">
                        Also creates {preview.newReferences.length} reference(s) and {preview.newVehicles.length} vehicle(s).
                        {preview.duplicateCount > 0 && (
                            <span className="ml-1 font-medium text-amber-600">
                                {preview.duplicateCount} row(s) match an invoice + date already in the system and will be skipped.
                            </span>
                        )}
                    </p>

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
                                Import {preview.newCount} new{preview.duplicateCount > 0 ? ` (skip ${preview.duplicateCount})` : ''}
                            </button>
                        }
                    >
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left text-xs uppercase text-slate-500">
                                        <th className="py-2 pr-4">Status</th>
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
                                        <tr key={i} className={'border-b last:border-0 ' + (r._duplicate ? 'bg-amber-50 text-slate-400' : '')}>
                                            <td className="py-2 pr-4">
                                                {r._duplicate
                                                    ? <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700">Duplicate</span>
                                                    : <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">New</span>}
                                            </td>
                                            <td className="py-2 pr-4 whitespace-nowrap">{fmtDate(r.transaction_date)}</td>
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

function Stat({ label, value, accent = 'text-navy-900' }) {
    return (
        <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <p className="text-xs uppercase text-slate-500">{label}</p>
            <p className={'mt-1 text-2xl font-bold ' + accent}>{value}</p>
        </div>
    );
}
