<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\V1\StoreController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Web\DashboardController;

use App\Http\Controllers\Web\ActionController;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::get('/products', [DashboardController::class, 'products'])->name('products.index');
Route::get('/orders', [DashboardController::class, 'orders'])->name('orders.index');
Route::get('/customers/{customer}/ledger', [DashboardController::class, 'ledger'])->name('ledger.show');

Route::post('/web/products', [ActionController::class, 'storeProduct'])->name('web.products.store');
Route::post('/web/orders', [ActionController::class, 'storeOrder'])->name('web.orders.store');
Route::post('/web/payments', [ActionController::class, 'storePayment'])->name('web.payments.store');
Route::delete('/web/orders/{order}', [ActionController::class, 'deleteOrder'])->name('web.orders.delete');



