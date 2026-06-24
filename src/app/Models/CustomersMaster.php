<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomersMaster extends Model
{
    protected $table = 'Customers_Master_T';



    protected $fillable = [
        'Customer_Code',
        'Customer_Type_Id',
        'User_Id',
        'Customer_Full_Name',
        'Telephone_Country_Code',
        'Telephone',
        'Customer_Profile_Image_Path',
        'Customer_Profile_Image_Size',
        'Customer_Profile_Image_Extension',
        'Customer_Profile_Image_Type',
        'updated_at'
    ];




    // app/Models/User.php
    public function favorites()
    {
        // user ↔ products via Favorites_Master_T
        return $this->belongsToMany(
            Products::class,         // related
            'Favorites_Master_T',               // pivot table
            'Customers_Id',                     // foreign key on pivot that points to this model
            'Products_Id'                       // foreign key on pivot that points to Product
        )->wherePivotNull('deleted_at')->withTimestamps();
    }

    // quick helper to check if a product is favorited
    public function hasFavorited(int $productId): bool
    {
        return Favorite::query()
            ->where('Customers_Id', $this->getKey())
            ->where('Products_Id', $productId)
            ->exists();
    }


    public function cartItems()
{
    return $this->hasMany(CustomerCart::class, 'Customers_Id', 'id');
}
}
