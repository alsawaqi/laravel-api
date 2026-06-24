<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Favorite extends Model
{
    use SoftDeletes;

    protected $table = 'Favorites_Master_T';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = ['Products_Id', 'Customers_Id', 'deleted_at'];

    public function product()
    {
        return $this->belongsTo(Products::class, 'Products_Id');
    }

    public function user() // or customer()
    {
        return $this->belongsTo(CustomersMaster::class, 'Customers_Id'); // or Customer::class
    }
}
