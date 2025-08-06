<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductSpecificationProduct extends Model
{
    //
    protected $table = 'Product_Specification_Product_T';
    public function description()
        {
            return $this->belongsTo(ProductSpecificationDescription::class, 'Product_Specification_Description_Id');
        }

    public function product()
        {
            return $this->belongsTo(Products::class, 'Product_Id');
        }
}
