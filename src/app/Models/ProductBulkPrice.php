<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Quantity-tier bulk price row for a master product.
 *
 * Table (Products_Bulk_Prices_T) is created by an isc-admin-api migration —
 * every read in this repo is Schema::hasTable-guarded so a lagging prod DB
 * keeps today's behavior byte-for-byte.
 */
class ProductBulkPrice extends Model
{
    protected $table = 'Products_Bulk_Prices_T';

    protected $guarded = [];

    protected $casts = [
        'Products_Id' => 'integer',
        'Min_Qty' => 'integer',
        'Max_Qty' => 'integer',
        'Unit_Price' => 'float',
    ];
}
