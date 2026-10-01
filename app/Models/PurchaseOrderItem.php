<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
class PurchaseOrderItem extends Model
{
    use HasFactory, SoftDeletes;
   protected $fillable = [
   'purchase_order_id', 
   'product_id',
   'warehouse_id', 
   'quantity',
   'unit_type',
   'conversion_factor',
   'unit_name',
   'unit_price',
   'total'
   ];

   protected $casts = [
    'conversion_factor' => 'integer',
   ];

   public function baseQuantity(): int
   {
    return (int) $this->quantity * $this->conversion_factor;
   }

   public function purchaseOrder()
   {
    return $this->belongsTo(PurchaseOrder::class);
   }
   public function product()
   {
    return $this->belongsTo(Product::class);
   }
   public function warehouse()
   {
    return $this->belongsTo(Warehouse::class);
   }
   

}
