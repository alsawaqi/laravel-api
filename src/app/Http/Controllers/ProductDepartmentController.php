<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ProductDepartment;
use Illuminate\Support\Facades\DB;
use App\Support\Localization\StorefrontArabicFields;

class ProductDepartmentController extends Controller
{
    public function index(){
        return response()->json(ProductDepartment::select(StorefrontArabicFields::departmentSelect())->orderby('id', 'DESC')->get());
    }


    // Fetch subcategories by department
    public function getSubCategories($id)
    {
         return response()->json(DB::table('Products_Sub_Department_T')
                                        ->where('Products_Departments_Id', $id)
                                        ->select(StorefrontArabicFields::subDepartmentSelect())
                                        ->get());
    }

    // Fetch sub-subcategories by subcategory
    public function getSubSubCategories($id)
    {
         return response()->json(DB::table('Products_Sub_Sub_Department_T')
                                ->where('Product_Sub_Department_Id', $id)
                                ->select(StorefrontArabicFields::subSubDepartmentSelect())
                                ->get());
    }
}
