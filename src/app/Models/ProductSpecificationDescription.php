<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductSpecificationDescription extends Model
{
   
    protected $table = 'Product_Specification_Description_T';

    public function values()
{
    return $this->hasMany(ProductSpecificationProduct::class, 'Product_Specification_Description_Id');
}
}
