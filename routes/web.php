<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\BulkPaymentController;
use App\Http\Controllers\CreditPaymentController;
use App\Http\Controllers\CustomFieldController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EntryController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\LedgerEntryController;
use App\Http\Controllers\MasterController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RecycleBinController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // Unified Add Entry page (transaction / daily-credit / borrowed)
    Route::get('/add-entry', [EntryController::class, 'create'])
        ->middleware('role:super_admin,admin,accountant')->name('entry.create');

    // Unified Operations workspace (list all types by filter + bulk delete)
    Route::get('/operations', [OperationsController::class, 'index'])->name('operations.index');
    Route::post('/operations/bulk-delete', [OperationsController::class, 'bulkDestroy'])
        ->middleware('role:super_admin,admin')->name('operations.bulk-delete');

    // Transactions — writes limited to super_admin/admin/accountant
    Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');
    Route::middleware('role:super_admin,admin,accountant')->group(function () {
        Route::get('transactions/create', [TransactionController::class, 'create'])->name('transactions.create');
        Route::post('transactions', [TransactionController::class, 'store'])->name('transactions.store');
        Route::get('transactions/{transaction}/edit', [TransactionController::class, 'edit'])->name('transactions.edit');
        Route::put('transactions/{transaction}', [TransactionController::class, 'update'])->name('transactions.update');
        Route::delete('transactions/{transaction}', [TransactionController::class, 'destroy'])->name('transactions.destroy');
    });

    // Books (running-balance ledgers)
    Route::get('books/cash-bank', [BookController::class, 'cashBank'])->name('books.cashbank');
    Route::get('books/cash', [BookController::class, 'cashBook'])->name('books.cash'); // export/deep-link
    Route::get('books/bank', [BookController::class, 'bankBook'])->name('books.bank'); // export/deep-link
    Route::get('books/ledger', [BookController::class, 'ledger'])->name('books.ledger');

    // Invoices (generated from transactions)
    Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('invoices/{transaction}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::get('invoices/{transaction}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');

    // Bulk Return / Bulk Payment (settle many ledger entries at once)
    Route::get('bulk-payment/{slug}', [BulkPaymentController::class, 'index'])->name('bulk.index');
    Route::post('bulk-payment/{slug}', [BulkPaymentController::class, 'store'])
        ->middleware('role:super_admin,admin,accountant')->name('bulk.store');

    // Daily Credit & Borrowed Amount (typed ledger)
    Route::get('ledger/{slug}/export', [LedgerEntryController::class, 'export'])->name('ledger.export');
    Route::get('ledger/{slug}', [LedgerEntryController::class, 'index'])->name('ledger.index');
    Route::middleware('role:super_admin,admin,accountant')->group(function () {
        Route::post('ledger/{slug}', [LedgerEntryController::class, 'store'])->name('ledger.store');
        Route::put('ledger/{slug}/{ledgerEntry}', [LedgerEntryController::class, 'update'])->name('ledger.update');
        Route::delete('ledger/{slug}/{ledgerEntry}', [LedgerEntryController::class, 'destroy'])->name('ledger.destroy');
    });

    // Credits / receivables
    Route::get('credits', [CreditPaymentController::class, 'index'])->name('credits.index');
    Route::post('credits', [CreditPaymentController::class, 'store'])
        ->middleware('role:super_admin,admin,accountant')->name('credits.store');

    // Masters (read for all; writes for super_admin/admin)
    Route::get('masters/{master}', [MasterController::class, 'index'])->name('masters.index');
    Route::middleware('role:super_admin,admin')->group(function () {
        Route::post('masters/{master}', [MasterController::class, 'store'])->name('masters.store');
        Route::put('masters/{master}/{id}', [MasterController::class, 'update'])->name('masters.update');
        Route::delete('masters/{master}/{id}', [MasterController::class, 'destroy'])->name('masters.destroy');
    });

    // Administration (Super Admin only)
    Route::middleware('role:super_admin')->group(function () {
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

        Route::get('activity', [ActivityLogController::class, 'index'])->name('activity.index');

        // No-code custom fields for the transaction form
        Route::get('custom-fields', [CustomFieldController::class, 'index'])->name('custom-fields.index');
        Route::post('custom-fields', [CustomFieldController::class, 'store'])->name('custom-fields.store');
        Route::put('custom-fields/{customField}', [CustomFieldController::class, 'update'])->name('custom-fields.update');
        Route::delete('custom-fields/{customField}', [CustomFieldController::class, 'destroy'])->name('custom-fields.destroy');

        // Recycle bin (soft-deleted transactions)
        Route::get('bin', [RecycleBinController::class, 'index'])->name('bin.index');
        Route::put('bin/{id}/restore', [RecycleBinController::class, 'restore'])->name('bin.restore');
        Route::delete('bin/{id}', [RecycleBinController::class, 'forceDelete'])->name('bin.force-delete');

        // Backups
        Route::get('backup', [BackupController::class, 'index'])->name('backup.index');
        Route::post('backup', [BackupController::class, 'create'])->name('backup.create');
        Route::get('backup/{name}/download', [BackupController::class, 'download'])->name('backup.download');
        Route::delete('backup/{name}', [BackupController::class, 'destroy'])->name('backup.destroy');

        Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::put('settings/company', [SettingsController::class, 'updateCompany'])->name('settings.company');
        Route::post('settings/database/test', [SettingsController::class, 'testDatabase'])->name('settings.database.test');
        Route::put('settings/database', [SettingsController::class, 'updateDatabase'])->name('settings.database');
    });

    // Tools
    Route::get('import', [ImportController::class, 'index'])->name('import.index');
    Route::middleware('role:super_admin,admin')->group(function () {
        Route::post('import/preview', [ImportController::class, 'preview'])->name('import.preview');
        Route::post('import/commit', [ImportController::class, 'commit'])->name('import.commit');
    });
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/{type}/export', [ReportController::class, 'export'])->name('reports.export');
    Route::get('reports/{type}', [ReportController::class, 'show'])->name('reports.show');

    // Profile (Breeze)
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
