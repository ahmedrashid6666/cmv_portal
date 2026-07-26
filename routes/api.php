<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\TransactionApiController;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Reference;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public: obtain an API token (for the future Flutter app)
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', fn (Request $request) => $request->user());
    Route::post('/logout', [AuthController::class, 'logout']);

    // Master dropdown data for the mobile app
    Route::get('/masters/customers', fn () => Customer::orderBy('name')->get(['id', 'name']));
    Route::get('/masters/references', fn () => Reference::orderBy('name')->get(['id', 'name']));
    Route::get('/masters/vehicles', fn () => Vehicle::orderBy('number')->get(['id', 'number']));
    Route::get('/masters/payment-methods', fn () => PaymentMethod::orderBy('name')->get(['id', 'name', 'type']));
    Route::get('/masters/expense-categories', fn () => ExpenseCategory::orderBy('name')->get(['id', 'name']));

    // Transactions — writes limited to super_admin/admin/accountant
    Route::get('/transactions', [TransactionApiController::class, 'index']);
    Route::get('/transactions/{transaction}', [TransactionApiController::class, 'show']);
    Route::middleware('role:super_admin,admin,accountant')->group(function () {
        Route::post('/transactions', [TransactionApiController::class, 'store']);
        Route::put('/transactions/{transaction}', [TransactionApiController::class, 'update']);
        Route::delete('/transactions/{transaction}', [TransactionApiController::class, 'destroy']);
    });
});
