<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductVendorOfferBulkPrice extends Model
{
    protected $table = 'Products_Vendor_Offer_Bulk_Prices_T';

    protected $guarded = ['id'];

    protected $casts = ['Min_Qty' => 'integer', 'Max_Qty' => 'integer', 'Unit_Price' => 'decimal:3'];
}
