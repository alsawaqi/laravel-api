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
        'Telephone_Country_Code',
        'Telephone',
        'Fax',
        'Gsm',
        'Email',
        'Designation',
        'Title_Id',
        'Remarks',
        'Updated_status',
        'Created_date',
        'Region_Id',
        'District_Id',
        'Is_Default',
    ];

    protected $casts = [
        'Is_Default' => 'boolean',
    ];

    protected $appends = [
        'is_default',
    ];

    public function getIsDefaultAttribute(): bool
    {
        return (bool)($this->attributes['Is_Default'] ?? false);
    }




       public function customer()
    {
        return $this->belongsTo(CustomersMaster::class, 'Customers_Contact_Id', 'id');
    }


    public function country()
    {
        return $this->belongsTo(Country::class, 'Country_Id');
    }



    public function region()
    {
        return $this->belongsTo(Region::class, 'Region_Id');
    }


    public function district()
    {
        return $this->belongsTo(District::class, 'District_Id');
    }

    public function state()
    {
        return $this->belongsTo(State::class, 'State_Id');
    }

    public function city()
    {
        return $this->belongsTo(City::class, 'City_Id');
    }

    public function title()
    {
        return $this->belongsTo(Title::class, 'Title_Id');
    }
}
