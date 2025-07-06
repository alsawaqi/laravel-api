<?php

use Illuminate\Http\Request;
use App\Models\ProductBrands;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProductBrandsController;
use App\Http\Controllers\ProductDepartmentController;
use App\Http\Controllers\ProductsSubSubDepartmentController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::controller(ProductDepartmentController::class)->group(function () {
       Route::get('/productdepartment', 'index');
       Route::get('/categories/{id}/subcategories','getSubCategories');
       Route::get('/subcategories/{id}/subsubcategories','getSubSubCategories');

});   


Route::controller(ProductsSubSubDepartmentController::class)->group(function () {
       Route::get('/subsubdepartments/{subsub}', 'index');
       
});



Route::controller(ProductBrandsController::class)->group(function () {

       Route::get('/productbrand', 'index');


});



 