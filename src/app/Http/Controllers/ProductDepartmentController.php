<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ProductDepartment;
use Illuminate\Support\Facades\DB;

class ProductDepartmentController extends Controller
{
    public function index(){
        return response()->json(ProductDepartment::orderby('id', 'DESC')->get());
    }


    // Fetch subcategories by department
    public function getSubCategories($id)
    {
         return response()->json(DB::table('Products_Sub_Department_T')
                                        ->where('product_department_id', $id)
                                        ->select('id', 'name', 'product_department_id','image_path')
                                        ->get());
    }

    // Fetch sub-subcategories by subcategory
    public function getSubSubCategories($id)
    {
         return response()->json(DB::table('Products_Sub_Sub_Department_T')
                                ->where('product_sub_department_id', $id)
                                ->select('id', 'name', 'product_sub_department_id','image_path')
                                ->get());
    }
}
