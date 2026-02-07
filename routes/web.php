<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\BalanceController;
// Dashboard
Route::get('/', [DashboardController::class, 'index'])->name('dashboard.index');

// Products
Route::get('/products', [ProductController::class, 'index'])->name('products.index');
Route::get('/products/create' , [ProductController::class, 'create'])->name('products.create');
Route::post('/products' , [ProductController::class, 'store'])->name('products.store');
Route::get('products/edit/{product}' , [ProductController::class , 'edit'])->name('products.edit');
Route::put('products/update/{product}' , [ProductController::class , 'update'])->name('products.update');
Route::delete('products/destroy/{product}' , [ProductController::class , 'destroy'])->name('products.destroy');

// Orders
Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
Route::get('/orders/create' , [OrderController::class , 'create'])->name('orders.create');
Route::post('/orders' , [OrderController::class , 'store'])->name('orders.store');
Route::get('orders/show/{order}' , [OrderController::class , 'show'])->name('orders.show');
// Customers
Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
Route::get('/customers/create' , [CustomerController::class , 'create'])->name('customers.create');
Route::post('/customers' , [CustomerController::class , 'store'])->name('customers.store');
Route::get('customers/show/{customer}' , [CustomerController::class , 'show'])->name('customers.show');
Route::get('customers/edit/{customer}' , [CustomerController::class , 'edit'])->name('customers.edit');
Route::put('customers/update/{customer}' , [CustomerController::class , 'update'])->name('customers.update');
Route::delete('customers/destroy/{customer}' , [CustomerController::class , 'destroy'])->name('customers.destroy');
Route::get('customers/{customer}/balance' , [BalanceController::class , 'show'])->name('balances.show');
Route::post('customers/{customer}/balance' , [BalanceController::class , 'store'])->name('balances.show.store');
// Invoices
Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
Route::get('/invoices/show/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
Route::get('/invoices/create', [InvoiceController::class, 'create'])->name('invoices.create');
Route::post('/invoices', [InvoiceController::class, 'store'])->name('invoices.store');
Route::get('/invoices/edit/{invoice}', [InvoiceController::class, 'edit'])->name('invoices.edit');
Route::put('/invoices/update/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');
Route::delete('/invoices/destroy/{invoice}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');
// Inventory
Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');
Route::get('/inventory/show/{inventory}', [InventoryController::class, 'show'])->name('inventory.show');
Route::get('/inventory/create', [InventoryController::class, 'create'])->name('inventory.create');
Route::post('/inventory', [InventoryController::class, 'store'])->name('inventory.store');
Route::get('/inventory/edit/{inventory}', [InventoryController::class, 'edit'])->name('inventory.edit');
Route::put('/inventory/update/{inventory}', [InventoryController::class, 'update'])->name('inventory.update');
Route::delete('/inventory/destroy/{inventory}', [InventoryController::class, 'destroy'])->name('inventory.destroy');

// Balances
Route::get('/balances', [BalanceController::class, 'index'])->name('balances.index');
Route::get('/balances/create', [BalanceController::class, 'create'])->name('balances.create');
Route::post('/balances', [BalanceController::class, 'store'])->name('balances.store');

