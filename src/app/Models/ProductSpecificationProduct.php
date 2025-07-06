<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductSpecificationProduct extends Model
{
    //
    protected $table = 'Product_Specification_Product_T';
    public function description()
        {
            return $this->belongsTo(ProductSpecificationDescription::class, 'product_specification_description_id');
        }
}
