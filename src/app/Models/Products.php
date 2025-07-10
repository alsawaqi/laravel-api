<?php

namespace App\Models;

use Spatie\Sluggable\SlugOptions;
use Illuminate\Database\Eloquent\Model;

class Products extends Model
{
    //
    protected $table = 'Products_Master_T';



    public function getSlugOptions(): SlugOptions
      {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug');
     }

    public function getRouteKeyName()
     {
        return 'slug';
     }


    public function images()
    {
        return $this->hasMany(ProductsImage::class, 'product_id', 'id');
    }

    public function image()
    {
        return $this->hasOne(ProductsImage::class, 'product_id', 'id');
    }


    public function subSubDepartment()
    {
        return $this->belongsTo(ProductsSubSubDepartment::class, 'product_sub_sub_department_id');
    }

    public function specifications()
    {
        return $this->hasMany(ProductSpecificationProduct::class, 'product_id', 'id');
    }
}
