export const AED = (n) =>
    new Intl.NumberFormat('en-AE', { style: 'currency', currency: 'AED', minimumFractionDigits: 2 }).format(
        Number(n || 0),
    );

export const num = (n) =>
    new Intl.NumberFormat('en-AE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(n || 0));
