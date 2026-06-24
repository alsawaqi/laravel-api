<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductReview extends Model
{
    use SoftDeletes;

    protected $table = 'Product_Reviews_T';

    protected $fillable = [
        'Products_Id',
        'Customers_Id',
        'Orders_Placed_Id',
        'Orders_Placed_Details_Id',
        'Rating',
        'Title',
        'Body',
        'Status',
        'Verified_Purchase',
        'Helpful_Count',
        'Report_Count',
        'Moderated_By',
        'Moderated_At',
        'Moderator_Note',
    ];

    protected $casts = [
        'Rating' => 'integer',
        'Verified_Purchase' => 'boolean',
        'Helpful_Count' => 'integer',
        'Report_Count' => 'integer',
        'Moderated_At' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Products::class, 'Products_Id');
    }

    public function customer()
    {
        return $this->belongsTo(CustomersMaster::class, 'Customers_Id');
    }

    public function replies()
    {
        return $this->hasMany(ProductReviewReply::class, 'Product_Review_Id');
    }
}
