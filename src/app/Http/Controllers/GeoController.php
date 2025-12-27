<?php

namespace App\Http\Controllers;

use App\Models\Country;
use App\Models\Region;
use App\Models\District;
use App\Models\City;
use Illuminate\Http\Request;

class GeoController extends Controller
{
    public function countries()
    {
        return Country::orderBy('Country_Name')->get();
    }

    public function regionsByCountry($countryId)
    {
        return Region::where('Country_Id', $countryId)
            ->orderBy('Region_Name')
            ->get();
    }

    public function districtsByRegion($regionId)
    {
        return District::where('Region_Id', $regionId)
            ->orderBy('District_Name')
            ->get();
    }

    public function citiesByDistrict($districtId)
    {
        return City::where('District_Id', $districtId)
            ->orderBy('City_Name')
            ->get();
    }
}
