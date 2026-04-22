<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\StoreController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\LedgerEntryController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\WarehouseController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\AuthController;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);


Route::get('/users', [App\Http\Controllers\Api\V1\UserController::class, 'index']);
Route::post('/users', [App\Http\Controllers\Api\V1\UserController::class, 'store']);
Route::delete('/users/{user}', [App\Http\Controllers\Api\V1\UserController::class, 'destroy']);

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

Route::get('/payments', [PaymentController::class, 'index']);
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


Route::get('warehouses', [WarehouseController::class, 'index']);
Route::get('warehouses/{warehouse}', [WarehouseController::class, 'show']);
Route::post('/warehouses', [WarehouseController::class, 'store']);
Route::put('warehouses/{warehouse}', [WarehouseController::class, 'update']);
Route::delete('warehouses/{warehouse}', [WarehouseController::class, 'destroy'])->withTrashed();



Route::get('/inventory', [InventoryController::class, 'index']);
Route::get('/inventory/{inventory}', [InventoryController::class, 'show']);
Route::post('/inventory', [InventoryController::class, 'store']);
Route::put('/inventory/{inventory}', [InventoryController::class, 'update']);
Route::post('/inventory/{inventory}/adjust', [InventoryController::class, 'adjust']);
});