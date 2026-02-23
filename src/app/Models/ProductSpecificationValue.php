<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductSpecificationValue extends Model
{
    //

    protected $table = 'Product_Specification_Value_T';


     public function description()
        {
            return $this->belongsTo(ProductSpecificationDescription::class, 'product_specification_description_id', 'id');
        }


}
