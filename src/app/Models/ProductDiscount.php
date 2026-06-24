<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductDiscount extends Model
{
    use SoftDeletes;

    protected $table = 'Products_Discounts_T';

    protected $guarded = [];

    protected $casts = [
        'Product_Discount_Value' => 'float',
        'Product_Discount_Is_Active' => 'boolean',
        'Start_Date' => 'datetime',
        'End_Date' => 'datetime',
    ];
}
