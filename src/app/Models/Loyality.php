<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Loyality extends Model
{
    protected $table = 'Customers_Loyalty_T';

    protected $fillable = [
        'Customers_Loyalty_Code',
        'Customer_Id',
        'Points_Earned',
        'Points_Redeemed',
    ];


 
}
