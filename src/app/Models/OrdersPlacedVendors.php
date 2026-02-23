<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdersPlacedVendors extends Model
{
    //

    protected $table = 'Orders_Placed_Vendors_T';

    protected $fillable = [
        'Orders_Placed_Id',
        'Vendor_Id',
        'Vendor_Order_Code',
        'Sub_Total',
        'VAT',
        'Shipping',
        'Total',
        'Status',
        'Commission_Type',
        'Commission_Value',        
        'Commission_Amount',
        'Payout_Status',
    ];

  
}
