<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomersMaster extends Model
{
   protected $table = 'Customers_Master_T';



    protected $fillable = [
           'Customer_Code',
           'Customer_Type_Id',
           'User_Id',
           'Customer_Full_Name',
           'Telephone',
           'updated_at'
          ];
    
 
    

}
