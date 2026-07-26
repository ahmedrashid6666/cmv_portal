import { AED } from '@/lib/format';

export function StatCard({ label, value, money = true, accent = 'primary', icon }) {
    const accents = {
        primary: 'from-primary-500 to-primary-700',
        navy: 'from-navy-500 to-navy-700',
        red: 'from-accent-red to-accent-red-dark',
        green: 'from-emerald-500 to-emerald-700',
    };
    return (
        <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div className="flex items-center justify-between">
                <p className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</p>
                <span className={'flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-br text-white ' + accents[accent]}>
                    {icon}
                </span>
            </div>
            <p className="mt-2 text-2xl font-bold text-navy-900">{money ? AED(value) : value}</p>
        </div>
    );
}
