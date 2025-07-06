<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ProductsSubSubDepartment;
use App\Models\ProductSpecificationDescription;

class ProductsSubSubDepartmentController extends Controller
{
    //
    public function index(ProductsSubSubDepartment $subsub)
    {
        try{

              $filters = ProductSpecificationDescription::with('values')
                            ->where('product_sub_sub_department_id', $subsub->id)
                            ->get();
       
        return response()->json([
                                  'data'=>$subsub, 
                                  'filters' => $filters
                                ]);

        }catch(\Exception $e){



            return response()->json(['error' => $e->getMessage()], 404);
        }
       
        
    }
}
