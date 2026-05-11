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
   'unit_price', 
   'total'
   ];
   
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
