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
           'Telephone',
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
    )->withTimestamps();
}

// quick helper to check if a product is favorited
public function hasFavorited(int $productId): bool
{
    return $this->favorites()->whereKey($productId)->exists();
}
    
 
    

}
