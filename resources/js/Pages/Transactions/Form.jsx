import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import TransactionEntryForm from '@/Components/forms/TransactionEntryForm';
import { Head } from '@inertiajs/react';

export default function TransactionForm(props) {
    const editing = !!props.transaction;
    return (
        <AuthenticatedLayout header={editing ? 'Edit Transaction' : 'New Transaction'}>
            <Head title={editing ? 'Edit Transaction' : 'New Transaction'} />
            <TransactionEntryForm {...props} />
        </AuthenticatedLayout>
    );
}
