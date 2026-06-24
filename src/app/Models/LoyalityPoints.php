<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyalityPoints extends Model
{
 protected $table = 'System_Parameter_Loyalty_Points_T';

 protected $casts = [
  'Point' => 'decimal:3',
  'Earn_Amount' => 'decimal:3',
  'Earn_Points' => 'decimal:3',
  'Redeem_Points' => 'decimal:3',
  'Redeem_Amount' => 'decimal:3',
 ];
}
