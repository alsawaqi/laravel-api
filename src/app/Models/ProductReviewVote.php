<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductReviewVote extends Model
{
    protected $table = 'Product_Review_Votes_T';

    protected $fillable = [
        'Product_Review_Id',
        'Customers_Id',
        'Vote_Type',
        'Reason',
    ];
}
