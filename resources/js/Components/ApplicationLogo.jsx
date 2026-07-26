export default function ApplicationLogo({ className, ...props }) {
    return (
        <img
            src="/logo.png"
            alt="CMV Shipping"
            className={className}
            {...props}
        />
    );
}
