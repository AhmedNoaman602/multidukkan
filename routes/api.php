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
use App\Http\Controllers\Api\V1\UnitController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\SupplierPaymentController;
use App\Http\Controllers\Api\V1\SupplierProductController;
use App\Http\Controllers\Api\V1\AIController;

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
Route::post('/payments/auto', [PaymentController::class, 'autoPayment']);
Route::post('/payments', [PaymentController::class, 'store']);

Route::get('/customers', [CustomerController::class, 'index']);
Route::get('/customers/{customer}', [CustomerController::class, 'show']);
Route::post('/customers', [CustomerController::class, 'store']);
Route::put('/customers/{customer}', [CustomerController::class, 'update']);
Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->withTrashed();
Route::get('/customers/{customer}/balance', [LedgerEntryController::class, 'balance']);
Route::get('/customers/{customer}/ledger', [LedgerEntryController::class, 'history']);
Route::post('/customers/{customer}/credit', [LedgerEntryController::class, 'addCredit']);
Route::post('customers/{customer}/refund', [CustomerController::class, 'refund']);
Route::get('/customers/{customer}/summary', [LedgerEntryController::class, 'summary']);

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


Route::get('/units', [UnitController::class, 'index']);
Route::post('/units', [UnitController::class, 'store']);
Route::delete('/units/{unit}', [UnitController::class, 'destroy']);


Route::get('/purchase-orders', [PurchaseOrderController::class, 'index']);
Route::post('/purchase-orders', [PurchaseOrderController::class, 'store']);
Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show']);
Route::delete('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'destroy'])->withTrashed();

Route::get('/suppliers', [SupplierController::class, 'index']);
Route::post('/suppliers', [SupplierController::class, 'store']);
Route::get('/suppliers/{supplier}', [SupplierController::class, 'show']);
Route::put('/suppliers/{supplier}', [SupplierController::class, 'update']);
Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy'])->withTrashed();
Route::get('/suppliers/{supplier}/balance', [LedgerEntryController::class, 'supplierBalance']);
Route::get('/suppliers/{supplier}/ledger',  [LedgerEntryController::class, 'supplierHistory']);

Route::post('/supplier-payments', [SupplierPaymentController::class, 'store']);
Route::get('/supplier-payments', [SupplierPaymentController::class, 'index']);

Route::get('suppliers/{supplier}/products', [SupplierProductController::class, 'index']);
Route::post('suppliers/{supplier}/products/{product}', [SupplierProductController::class, 'attach']);
Route::delete('suppliers/{supplier}/products/{product}', [SupplierProductController::class, 'detach']);
Route::get('/suppliers/{supplier}/stock', [SupplierController::class, 'products']);

Route::post('/ai/describe-product', [AIController::class, 'describeProduct']);
Route::get('/ai/insights', [AIController::class, 'insights']);
Route::post('/ai/chat', [AIController::class, 'chat']);
});

