<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyHistory extends Model
{
    //

    protected $table = 'Customers_Loyalty_Transactions_T';

    protected $fillable = [
        'Loyalty_Transaction_Code',
        'Customer_Id',
        'Orders_Placed_Id',
        'Points_Earned',
        'Points_Redeemed',
        'Redeemed_Amount',
    ];

    protected $casts = [
        'Points_Earned' => 'integer',
        'Points_Redeemed' => 'integer',
        'Redeemed_Amount' => 'decimal:3',
    ];
 

}
