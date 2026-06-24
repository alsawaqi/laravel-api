<?php

namespace App\Models;

use Spatie\Sluggable\SlugOptions;
use Illuminate\Database\Eloquent\Model;

class Products extends Model
{
    //
    protected $table = 'Products_Master_T';


    public function favoritedBy()
    {
        // products ↔ users via Favorites_Master_T
        return $this->belongsToMany(
            CustomersMaster::class,            // or Customer::class
            'Favorites_Master_T',
            'Products_Id',
            'Customers_Id'
        )->wherePivotNull('deleted_at')->withTimestamps();
    }


    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('Product_Name')
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


    public function department()
    {
        return $this->belongsTo(ProductDepartment::class, 'Product_Department_Id');
    }


    public function subdepartment()
    {
        return $this->belongsTo(ProductSubDepartment::class, 'Product_Sub_Department_Id');
    }

    public function subSubDepartment()
    {
        return $this->belongsTo(ProductsSubSubDepartment::class, 'Product_Sub_Sub_Department_Id');
    }


    public function customercart()
    {
        return $this->hasMany(CustomerCart::class,'Products_Id','id');
    }

    public function reviews()
    {
        return $this->hasMany(ProductReview::class, 'Products_Id', 'id');
    }

    public function approvedReviews()
    {
        return $this->reviews()->where('Status', 'approved');
    }

    public function questions()
    {
        return $this->hasMany(ProductQuestion::class, 'Products_Id', 'id');
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
