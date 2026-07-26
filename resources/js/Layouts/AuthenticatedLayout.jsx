import Dropdown from '@/Components/Dropdown';
import { Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

function FlashToast() {
    const flash = usePage().props.flash || {};
    const [show, setShow] = useState(false);
    const message = flash.success || flash.error;
    const isError = !!flash.error;

    useEffect(() => {
        if (message) {
            setShow(true);
            const t = setTimeout(() => setShow(false), 4000);
            return () => clearTimeout(t);
        }
    }, [message, flash]);

    if (!show || !message) return null;
    return (
        <div className={'fixed bottom-6 right-6 z-50 max-w-sm rounded-lg px-4 py-3 text-sm font-medium text-white shadow-lg ' + (isError ? 'bg-accent-red' : 'bg-emerald-600')}>
            {message}
        </div>
    );
}

function NavItem({ href, active, children, icon }) {
    return (
        <Link
            href={href}
            className={
                'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition ' +
                (active
                    ? 'bg-primary-600 text-white shadow'
                    : 'text-navy-100 hover:bg-navy-700/60 hover:text-white')
            }
        >
            <span className="w-5 text-center text-base leading-none">{icon}</span>
            {children}
        </Link>
    );
}

function SectionLabel({ children }) {
    return (
        <p className="px-3 pb-1 pt-5 text-[11px] font-semibold uppercase tracking-wider text-navy-300">
            {children}
        </p>
    );
}

export default function AuthenticatedLayout({ header, children }) {
    const user = usePage().props.auth.user;
    const [open, setOpen] = useState(false);
    const current = (name) => route().current(name);
    const isSuperAdmin = user.role === 'super_admin';

    const nav = (
        <nav className="flex flex-1 flex-col gap-0.5">
            <NavItem href={route('dashboard')} active={current('dashboard')} icon="▤">
                Dashboard
            </NavItem>
            <NavItem href={route('transactions.index')} active={current('transactions.*')} icon="₪">
                Transactions
            </NavItem>
            <NavItem href={route('credits.index')} active={current('credits.*')} icon="◔">
                Credits
            </NavItem>

            <SectionLabel>Books</SectionLabel>
            <NavItem href={route('books.cashbank')} active={current('books.cashbank')} icon="₵">
                Cash &amp; Bank Book
            </NavItem>
            <NavItem href={route('books.ledger')} active={current('books.ledger')} icon="≣">
                Customer Ledger
            </NavItem>

            <SectionLabel>Master Data</SectionLabel>
            <NavItem href={route('masters.index', 'customers')} active={current('masters.*') && route().params.master === 'customers'} icon="◈">
                Customers
            </NavItem>
            <NavItem href={route('masters.index', 'references')} active={current('masters.*') && route().params.master === 'references'} icon="◇">
                References
            </NavItem>
            <NavItem href={route('masters.index', 'vehicles')} active={current('masters.*') && route().params.master === 'vehicles'} icon="⬒">
                Vehicles
            </NavItem>
            <NavItem href={route('masters.index', 'expense-categories')} active={current('masters.*') && route().params.master === 'expense-categories'} icon="⬓">
                Expense Categories
            </NavItem>
            <NavItem href={route('masters.index', 'payment-methods')} active={current('masters.*') && route().params.master === 'payment-methods'} icon="⬔">
                Payment Methods
            </NavItem>
            <NavItem href={route('masters.index', 'banks')} active={current('masters.*') && route().params.master === 'banks'} icon="⛁">
                Banks
            </NavItem>

            <SectionLabel>Tools</SectionLabel>
            <NavItem href={route('import.index')} active={current('import.*')} icon="↥">
                Import Excel
            </NavItem>
            <NavItem href={route('reports.index')} active={current('reports.*')} icon="▦">
                Reports
            </NavItem>

            {isSuperAdmin && (
                <>
                    <SectionLabel>Administration</SectionLabel>
                    <NavItem href={route('users.index')} active={current('users.*')} icon="◉">
                        Users
                    </NavItem>
                    <NavItem href={route('settings.index')} active={current('settings.*')} icon="⚙">
                        Settings
                    </NavItem>
                </>
            )}
        </nav>
    );

    return (
        <div className="min-h-screen bg-slate-50">
            {/* Sidebar */}
            <aside className="fixed inset-y-0 left-0 z-30 hidden w-64 flex-col bg-navy-800 px-3 py-4 lg:flex">
                <div className="flex items-center gap-2 px-2 pb-4">
                    <img src="/logo.png" alt="CMV" className="h-9 w-9" />
                    <div className="leading-tight">
                        <p className="text-sm font-bold text-white">CMV Shipping</p>
                        <p className="text-[11px] text-navy-300">Accounts System</p>
                    </div>
                </div>
                {nav}
                <p className="px-3 pt-4 text-[11px] text-navy-400">v0.1 · Phase 1</p>
            </aside>

            {/* Mobile sidebar */}
            {open && (
                <div className="fixed inset-0 z-40 lg:hidden">
                    <div className="absolute inset-0 bg-black/40" onClick={() => setOpen(false)} />
                    <aside className="absolute inset-y-0 left-0 flex w-64 flex-col bg-navy-800 px-3 py-4">
                        <div className="flex items-center gap-2 px-2 pb-4">
                            <img src="/logo.png" alt="CMV" className="h-9 w-9" />
                            <p className="text-sm font-bold text-white">CMV Shipping</p>
                        </div>
                        {nav}
                    </aside>
                </div>
            )}

            {/* Main column */}
            <div className="lg:pl-64">
                <header className="sticky top-0 z-20 flex h-16 items-center justify-between border-b border-slate-200 bg-white px-4 sm:px-6">
                    <div className="flex items-center gap-3">
                        <button
                            className="rounded-md p-2 text-navy-700 hover:bg-slate-100 lg:hidden"
                            onClick={() => setOpen(true)}
                        >
                            ☰
                        </button>
                        <div className="text-lg font-semibold text-navy-800">{header}</div>
                    </div>

                    <Dropdown>
                        <Dropdown.Trigger>
                            <button className="flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1.5 text-sm font-medium text-navy-800 hover:bg-slate-200">
                                <span className="flex h-7 w-7 items-center justify-center rounded-full bg-primary-600 text-xs font-bold text-white">
                                    {user.name?.charAt(0)?.toUpperCase()}
                                </span>
                                <span className="hidden sm:inline">{user.name}</span>
                            </button>
                        </Dropdown.Trigger>
                        <Dropdown.Content>
                            <div className="border-b px-4 py-2 text-xs text-slate-500">
                                {user.email}
                                <br />
                                <span className="font-semibold text-primary-700">
                                    {(user.role || '').replace('_', ' ')}
                                </span>
                            </div>
                            <Dropdown.Link href={route('profile.edit')}>Profile</Dropdown.Link>
                            <Dropdown.Link href={route('logout')} method="post" as="button">
                                Log Out
                            </Dropdown.Link>
                        </Dropdown.Content>
                    </Dropdown>
                </header>

                <main className="p-4 sm:p-6">{children}</main>
            </div>

            <FlashToast />
        </div>
    );
}
