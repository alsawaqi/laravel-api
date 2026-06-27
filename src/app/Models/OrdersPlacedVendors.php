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

        // Return/refund + net payout fields. place() sets these at creation; they MUST be
        // fillable or create() silently drops them and Net_Sub_Total defaults to 0,
        // which makes the admin payout (Net_Sub_Total - commission) go negative.
        'Returned_Quantity',
        'Refunded_Amount',
        'Net_Sub_Total',
        'Adjusted_Commission_Amount',
        'Net_Payout_Amount',
        'Payout_Adjustment_Amount',
    ];


}
