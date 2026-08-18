import { usePage } from '@inertiajs/react';

export default function ApplicationLogo({ className, ...props }) {
    const { branding } = usePage().props;

    return (
        <img
            src={branding.logo}
            alt={branding.name}
            className={className}
            {...props}
        />
    );
}
