<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
class AppServiceProvider extends ServiceProvider
{
   
    protected $policies = [
    Order::class    => OrderPolicy::class,
    Payment::class  => PaymentPolicy::class,
    Product::class  => ProductPolicy::class,
    Customer::class => CustomerPolicy::class,
    Warehouse::class => WarehousePolicy::class,
    Inventory::class => InventoryPolicy::class,
    Store::class => StorePolicy::class,
];
 /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);
    }
}
