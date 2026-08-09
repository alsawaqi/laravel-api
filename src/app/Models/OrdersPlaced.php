<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdersPlaced extends Model
{
    //

    protected $table = 'Orders_Placed_T';

    protected $guarded = [];

    /**
     * Pickup-handover fields written by the admin app. The private upload object key of
     * the collector's ID copy and the internal admin user id are sensitive
     * and must never be echoed in customer-facing responses.
     */
    protected $hidden = [
        'Pickup_Id_Image_Path',
        'Picked_Up_By',
    ];
}
