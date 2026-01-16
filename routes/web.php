<?php

use Illuminate\Support\Facades\Route;

// Dashboard
Route::get('/', function () {
    return view('dashboard.index');
})->name('dashboard.index');

// Products
Route::get('/products', function () {
    return view('products.index');
})->name('products.index');

// Orders
Route::get('/orders', function () {
    return view('orders.index');
})->name('orders.index');

// Customers
Route::get('/customers', function () {
    return view('customers.index');
})->name('customers.index');

// Invoices
Route::get('/invoices', function () {
    return view('invoices.index');
})->name('invoices.index');

// Inventory
Route::get('/inventory', function () {
    return view('inventory.index');
})->name('inventory.index');