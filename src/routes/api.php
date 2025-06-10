<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProductDepartmentController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::controller(ProductDepartmentController::class)->group(function () {
       Route::get('/productdepartment', 'index');
       Route::get('/categories/{id}/subcategories','getSubCategories');
       Route::get('/subcategories/{id}/subsubcategories','getSubSubCategories');

});   



 