<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerBackInStockAlert extends Model
{
    protected $table = 'Customer_Back_In_Stock_Alerts_T';

    protected $guarded = [];

    protected $casts = [
        'Notified_At' => 'datetime',
    ];
}
