<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductReviewReply extends Model
{
    use SoftDeletes;

    protected $table = 'Product_Review_Replies_T';

    protected $fillable = [
        'Product_Review_Id',
        'Reply_Type',
        'User_Id',
        'Vendor_User_Id',
        'Vendor_Id',
        'Body',
        'Status',
    ];
}
