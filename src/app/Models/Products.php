<?php

namespace App\Models;

use Spatie\Sluggable\SlugOptions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

class Products extends Model
{
    // Soft deletes: Products_Master_T.deleted_at is added by an isc-admin-api
    // migration. That migration MUST be live before this code deploys — the
    // trait adds `deleted_at IS NULL` to every query on this model.
    use SoftDeletes;

    protected $table = 'Products_Master_T';

    /**
     * Internal admin-only money fields — customers must never see the
     * product cost or the minimum-selling floor in any serialized response.
     */
    protected $hidden = ['Product_Cost', 'Minimum_Selling_Price'];

    /**
     * Storefront visibility: only active products (Is_Active = 1). Guarded by
     * Schema::hasColumn because the prod DB may not have the column yet.
     */
    public function scopeActive($query)
    {
        if (Schema::hasColumn($this->getTable(), 'Is_Active')) {
            $query->where($this->getTable() . '.Is_Active', 1);
        }

        return $query;
    }

    /**
     * Route-model binding is only used on customer-facing routes (product
     * detail, reviews/questions, favorites toggle). Deleted products are
     * already excluded by SoftDeletes; deactivated products must 404 too.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return $this->where($field ?? $this->getRouteKeyName(), $value)
            ->active()
            ->first();
    }

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

    
    /**
     * Quantity-tier bulk prices, ordered for display/resolution. Only query
     * this relation behind Schema::hasTable('Products_Bulk_Prices_T') — the
     * table ships in an isc-admin-api migration and may lag on prod.
     */
    public function bulkPrices()
    {
        return $this->hasMany(ProductBulkPrice::class, 'Products_Id', 'id')
            ->orderBy('Min_Qty');
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
