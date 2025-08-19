<?php

use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RegionController;
use App\Http\Middleware\ForceJwtFromCookie;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\ProductsController;
use App\Http\Controllers\OrdersPlacedController;
use App\Http\Controllers\ProductBrandsController;
use App\Http\Controllers\ShippingQuoteController;
use App\Http\Controllers\CustomersContactController;
use App\Http\Controllers\DistrictController;
use App\Http\Controllers\ProductDepartmentController;
use App\Http\Controllers\ProductsSubSubDepartmentController;

 

Route::middleware([ForceJwtFromCookie::class,'auth:api'])->group(function () {


       Route::get('/user', function (Request $request) {
               return response()->json([
                                          'user' => Auth::guard('api')->user(),
                                          'data' =>'test'
              
                                         ]);
         });


         Route::post('/refresh', function (Request $request) {
              $refreshToken = $request->cookie('refresh_token');

              if (!$refreshToken) {
                     return response()->json(['message' => 'Refresh token missing'], 401);
              }

              try {
                     $newAccessToken = JWTAuth::setToken($refreshToken)->claims(['type' => 'access'])->refresh();
                     $accessCookie = cookie('token', $newAccessToken, 60, '/', null, false, true, false, 'Lax');
                     return response()->json(['message' => 'Refreshed'])->withCookie($accessCookie);
              } catch (\Exception $e) {
                     return response()->json(['message' => 'Refresh failed'], 401);
              }
        });


  Route::controller(OrdersPlacedController::class)->group(function () {
        Route::post('/orders/place', 'place');
        Route::get('/orders', 'index');
        Route::get('/orders/{id}/details', 'getOrderDetails');
    });




    Route::post('/v1/shipping/quotes', [ShippingQuoteController::class, 'quote']);



    Route::prefix('/contacts')->group(function () {

    Route::get('/', [CustomersContactController::class, 'index']);
    Route::post('/', [CustomersContactController::class, 'store']);
    Route::get('/by-country/{countryId}', [CustomersContactController::class, 'byCountry']);
    Route::get('/by-state/{stateId}', [CustomersContactController::class, 'byState']);
    Route::get('/{id}', [CustomersContactController::class, 'show']);
    Route::put('/{id}', [CustomersContactController::class, 'update']);
    Route::delete('/{id}', [CustomersContactController::class, 'destroy']);




  });



  Route::controller(CustomersContactController::class)->group(function () {
       Route::get('/countries', 'countries_index');
  });
        

      
              
});





 

 
Route::controller(ProductDepartmentController::class)->group(function () {
       Route::get('/productdepartment', 'index');
       Route::get('/categories/{id}/subcategories','getSubCategories');
       Route::get('/subcategories/{id}/subsubcategories','getSubSubCategories');

});   


Route::controller(RegionController::class)->group(function () {
       Route::get('/region', 'index');
   
});



Route::controller(DistrictController::class)->group(function () {
       Route::get('/district', 'index');
   
});


Route::controller(ProductsSubSubDepartmentController::class)->group(function () {
       Route::get('/subsubdepartments/{slug}', 'index');
       
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

 