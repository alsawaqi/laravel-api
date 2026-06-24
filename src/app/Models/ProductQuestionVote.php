<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductQuestionVote extends Model
{
    protected $table = 'Product_Question_Votes_T';

    protected $fillable = [
        'Product_Question_Id',
        'Customers_Id',
        'Vote_Type',
        'Reason',
    ];
}
