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

function NavGroup({ label, icon, active, open, onToggle, children }) {
    return (
        <div>
            <button
                type="button"
                onClick={onToggle}
                className={
                    'flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-semibold transition ' +
                    (active
                        ? 'text-white'
                        : 'text-navy-100 hover:bg-navy-700/60 hover:text-white')
                }
            >
                <span className="w-5 text-center text-base leading-none">{icon}</span>
                <span className="flex-1 text-left">{label}</span>
                <span className={'text-[10px] transition-transform duration-200 ' + (open ? 'rotate-90' : '')}>
                    ▶
                </span>
            </button>
            {open && (
                <div className="mb-1 ml-3 mt-0.5 space-y-0.5 border-l border-navy-700 pl-2">
                    {children}
                </div>
            )}
        </div>
    );
}

export default function AuthenticatedLayout({ header, children }) {
    const user = usePage().props.auth.user;
    const [open, setOpen] = useState(false);
    const current = (name) => route().current(name);
    const isSuperAdmin = user.role === 'super_admin';

    const masterDefs = [
        ['customers', 'Customers', '◈'],
        ['references', 'References', '◇'],
        ['vehicles', 'Vehicles', '⬒'],
        ['expense-categories', 'Expense Categories', '⬓'],
        ['payment-methods', 'Payment Methods', '⬔'],
        ['banks', 'Banks', '⛁'],
    ];

    const groups = [
        {
            key: 'books',
            label: 'Books',
            icon: '₵',
            items: [
                { label: 'Cash & Bank Book', href: route('books.cashbank'), icon: '₵', active: current('books.cashbank') },
                { label: 'Customer Ledger', href: route('books.ledger'), icon: '≣', active: current('books.ledger') },
            ],
        },
        {
            key: 'masters',
            label: 'Master Data',
            icon: '◈',
            items: masterDefs.map(([m, label, icon]) => ({
                label,
                icon,
                href: route('masters.index', m),
                active: current('masters.*') && route().params.master === m,
            })),
        },
        {
            key: 'tools',
            label: 'Tools',
            icon: '▦',
            items: [
                { label: 'Import Excel', href: route('import.index'), icon: '↥', active: current('import.*') },
                { label: 'Reports', href: route('reports.index'), icon: '▦', active: current('reports.*') },
            ],
        },
        ...(isSuperAdmin
            ? [{
                key: 'admin',
                label: 'Administration',
                icon: '⚙',
                items: [
                    { label: 'Users', href: route('users.index'), icon: '◉', active: current('users.*') },
                    { label: 'Activity Log', href: route('activity.index'), icon: '⌗', active: current('activity.*') },
                    { label: 'Custom Fields', href: route('custom-fields.index'), icon: '⊕', active: current('custom-fields.*') },
                    { label: 'Recycle Bin', href: route('bin.index'), icon: '♻', active: current('bin.*') },
                    { label: 'Backup', href: route('backup.index'), icon: '⤓', active: current('backup.*') },
                    { label: 'Settings', href: route('settings.index'), icon: '⚙', active: current('settings.*') },
                ],
            }]
            : []),
    ];

    // Accordion: only one group open at a time; the active page's group opens by default.
    const activeGroupKey = groups.find((g) => g.items.some((i) => i.active))?.key ?? null;
    const [openGroup, setOpenGroup] = useState(activeGroupKey);

    const canWrite = ['super_admin', 'admin', 'accountant'].includes(user.role);

    const nav = (
        <nav className="flex flex-1 flex-col gap-0.5">
            {canWrite && (
                <Link
                    href={route('entry.create')}
                    className="mb-1 flex items-center justify-center gap-2 rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow transition hover:bg-primary-500"
                >
                    <span className="text-base leading-none">＋</span> Add Entry
                </Link>
            )}
            <NavItem href={route('dashboard')} active={current('dashboard')} icon="▤">
                Dashboard
            </NavItem>
            <NavItem
                href={route('operations.index')}
                active={current('operations.*') || current('invoices.*') || current('credits.*')}
                icon="₪"
            >
                Operations
            </NavItem>
            {groups.map((g) => (
                <NavGroup
                    key={g.key}
                    label={g.label}
                    icon={g.icon}
                    active={g.items.some((i) => i.active)}
                    open={openGroup === g.key}
                    onToggle={() => setOpenGroup(openGroup === g.key ? null : g.key)}
                >
                    {g.items.map((i) => (
                        <NavItem key={i.label} href={i.href} active={i.active} icon={i.icon}>
                            {i.label}
                        </NavItem>
                    ))}
                </NavGroup>
            ))}
        </nav>
    );

    return (
        <div className="min-h-screen bg-slate-50">
            {/* Sidebar */}
            <aside className="fixed inset-y-0 left-0 z-30 hidden w-64 flex-col overflow-y-auto bg-navy-800 px-3 py-4 lg:flex">
                <div className="flex items-center gap-2 px-2 pb-4">
                    <img src="/logo.png" alt="CMV" className="h-9 w-9 brightness-0 invert" />
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
                    <aside className="absolute inset-y-0 left-0 flex w-64 flex-col overflow-y-auto bg-navy-800 px-3 py-4">
                        <div className="flex items-center gap-2 px-2 pb-4">
                            <img src="/logo.png" alt="CMV" className="h-9 w-9 brightness-0 invert" />
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
