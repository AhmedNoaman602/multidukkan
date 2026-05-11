<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Gate;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Warehouse;
use App\Models\Inventory;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\PurchaseOrder;
use App\Models\SupplierPayment;
use App\Policies\OrderPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\ProductPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\WarehousePolicy;
use App\Policies\InventoryPolicy;
use App\Policies\StorePolicy;
use App\Policies\SupplierPolicy;
use App\Policies\PurchaseOrderPolicy;
use App\Policies\SupplierPaymentPolicy;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Schema::defaultStringLength(191);

        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(Warehouse::class, WarehousePolicy::class);
        Gate::policy(Inventory::class, InventoryPolicy::class);
        Gate::policy(Store::class, StorePolicy::class);
        Gate::policy(Supplier::class, SupplierPolicy::class);
        Gate::policy(PurchaseOrder::class, PurchaseOrderPolicy::class);
        Gate::policy(SupplierPayment::class, SupplierPaymentPolicy::class);
    }
}