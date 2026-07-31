<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
use App\Models\Expense;
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
use App\Policies\ExpensePolicy;
use App\Observers\StoreObserver;
use App\Observers\CustomerObserver;
use App\Observers\OrderObserver;
use App\Observers\ProductObserver;
use App\Observers\SupplierObserver;
use App\Observers\PurchaseOrderObserver;
use App\Observers\ExpenseObserver;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->environment('local')) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(\App\Providers\TelescopeServiceProvider::class);
        }
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
        Gate::policy(Expense::class, ExpensePolicy::class);
        Store::observe(StoreObserver::class);
        Customer::observe(CustomerObserver::class);
        Order::observe(OrderObserver::class);
        Product::observe(ProductObserver::class);
        Supplier::observe(SupplierObserver::class);
        PurchaseOrder::observe(PurchaseOrderObserver::class);
        Expense::observe(ExpenseObserver::class);

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('ai', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });
    }
}