<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomersContact extends Model
{
    //
    protected $table = 'Customers_Contact_T';
        protected $fillable = [
        'Customer_Contact_Code',
        'Type',
        'Customers_Contact_Id',
        'City_Id',
      
        'Country_Id',
        'Contact_Person_Name',
        'Telephone',
        'Fax',
        'Gsm',
        'Email',
        'Designation',
        'Remarks',
        'Updated_status',
        'Created_date',
        'Region_Id',
        'District_Id'
    ];

    public function country()
    {
        return $this->belongsTo(Country::class, 'Country_Id');
    }

    public function state()
    {
        return $this->belongsTo(State::class, 'State_Id');
    }

    public function city()
    {
        return $this->belongsTo(City::class, 'City_Id');
    }
}
