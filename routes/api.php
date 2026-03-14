<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\StoreController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\LedgerEntryController;
use App\Http\Controllers\Api\V1\ProductController;
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');



Route::get('/stores', [StoreController::class, 'index']);
Route::get('/stores/{store}', [StoreController::class, 'show']);
Route::post('/stores', [StoreController::class, 'store']);
Route::put('/stores/{store}', [StoreController::class, 'update']);
Route::delete('/stores/{store}', [StoreController::class, 'destroy'])->withTrashed();

Route::get('/orders', [OrderController::class, 'index']);
Route::post('/orders', [OrderController::class, 'store']);
Route::delete('/orders/{order}', [OrderController::class, 'destroy'])->withTrashed();
Route::get('/orders/{order}', [OrderController::class, 'show']);
Route::patch('/orders/{order}', [OrderController::class, 'update']);

Route::post('/payments', [PaymentController::class, 'store']);

Route::get('/customers', [CustomerController::class, 'index']);
Route::get('/customers/{customer}', [CustomerController::class, 'show']);
Route::post('/customers', [CustomerController::class, 'store']);
Route::put('/customers/{customer}', [CustomerController::class, 'update']);
Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->withTrashed();
Route::get('/customers/{customer}/balance', [LedgerEntryController::class, 'balance']);
Route::get('/customers/{customer}/ledger', [LedgerEntryController::class, 'history']);
Route::post('/customers/{customer}/credit', [LedgerEntryController::class, 'addCredit']);


Route::get('products', [ProductController::class, 'index']);
Route::get('products/{product}', [ProductController::class, 'show']);
Route::post('/products', [ProductController::class, 'store']);
Route::put('products/{product}', [ProductController::class, 'update']);
Route::delete('products/{product}', [ProductController::class, 'destroy'])->withTrashed();
