<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\ProductsController;
use App\Http\Controllers\OrdersPlacedController;
use App\Http\Controllers\ProductBrandsController;
use App\Http\Controllers\ProductDepartmentController;
use App\Http\Controllers\ProductsSubSubDepartmentController;

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
     return response()->json(['user' => Auth::user()]);
});


Route::post('/orders/place', [OrdersPlacedController::class, 'place'])->middleware('auth:sanctum');



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

Route::controller(ProductsController::class)->group(function () {
        Route::get('/products/{subsub:slug}', 'show');
        Route::get('/products/details/{product:slug}', 'detail');
});


Route::controller(AuthController::class)->group(function () {
    Route::post('/register', 'register');
    Route::post('/login', 'login');
    Route::post('/logout', 'logout');

});

 