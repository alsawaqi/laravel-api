<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class CustomerCart extends Model
{
    use SoftDeletes;

    public $table = 'Customers_Carts_T';

    protected $fillable = [
        'Cart_Code', 
        'Customers_Id',
        'Products_Id',
        'Quantity',
        'deleted_at',
        'created_at',
        'updated_at'
    ];


    public function customer()
    {
        return $this->belongsTo(CustomersMaster::class, 'Customers_Id', 'id');
    }

    public function product()
    {
        return $this->belongsTo(Products::class, 'Products_Id', 'id');
    }   
}
