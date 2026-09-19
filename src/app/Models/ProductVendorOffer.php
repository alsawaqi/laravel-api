<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVendorOffer extends Model
{
    use SoftDeletes;

    protected $table = 'Products_Vendor_Offers_T';

    protected $guarded = ['id'];

    protected $hidden = ['Product_Cost', 'Minimum_Selling_Price', 'Commission_Type', 'Commission_Value'];

    protected $casts = [
        'Products_Id' => 'integer', 'Vendor_Id' => 'integer', 'Product_Stock' => 'integer',
        'Is_Active' => 'boolean', 'Product_Price' => 'decimal:3', 'Commission_Value' => 'decimal:3',
    ];

    public function bulkPrices()
    {
        return $this->hasMany(ProductVendorOfferBulkPrice::class, 'Vendor_Offer_Id')->orderBy('Min_Qty');
    }
}
