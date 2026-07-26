export function Card({ title, action, children, className = '' }) {
    return (
        <div className={'rounded-xl border border-slate-200 bg-white shadow-sm ' + className}>
            {(title || action) && (
                <div className="flex items-center justify-between border-b border-slate-100 px-5 py-3">
                    <h3 className="text-sm font-semibold text-navy-800">{title}</h3>
                    {action}
                </div>
            )}
            <div className="p-5">{children}</div>
        </div>
    );
}
