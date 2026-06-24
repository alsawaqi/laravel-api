<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductQuestion extends Model
{
    use SoftDeletes;

    protected $table = 'Product_Questions_T';

    protected $fillable = [
        'Products_Id',
        'Customers_Id',
        'Question',
        'Status',
        'Helpful_Count',
        'Report_Count',
        'Moderated_By',
        'Moderated_At',
        'Moderator_Note',
    ];

    protected $casts = [
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

    public function answers()
    {
        return $this->hasMany(ProductQuestionAnswer::class, 'Product_Question_Id');
    }
}
