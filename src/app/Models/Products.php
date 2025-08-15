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
        return $this->hasMany(ProductsImage::class, 'Products_Id', 'id');
    }

    public function image()
    {
        return $this->hasOne(ProductsImage::class, 'Products_Id', 'id');
    }


    public function subSubDepartment()
    {
        return $this->belongsTo(ProductsSubSubDepartment::class, 'Product_Sub_Sub_Department_Id');
    }

   


    // App\Models\Products (Products_Master_T)
     public function specifications()
    {
        return $this->hasMany(
            ProductSpecificationProduct::class,
            'Product_Id',
            'id'
        );
    }
}
