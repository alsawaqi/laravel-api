<?php

use Carbon\Carbon;
use App\Models\User;
use App\Events\OrderCreated;
use Illuminate\Http\Request;
use App\Models\CustomersMaster;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\URL;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\GeoController;
use App\Http\Controllers\VatController;
use App\Events\AdminNotificationCreated;
use App\Models\ConxDatabaseNotification;
use App\Http\Controllers\RegionController;
use App\Http\Middleware\ForceJwtFromCookie;
use App\Notifications\NewOrderNotification;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\DistrictController;
use App\Http\Controllers\LoyalityController;
use App\Http\Controllers\ProductsController;
use App\Http\Controllers\FavoritesController;
use App\Http\Controllers\LocationsController;
use App\Services\NotificationBroadcastService;
use App\Http\Controllers\CustomerCartController;
use App\Http\Controllers\OrdersPlacedController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\ProductBrandsController;
use App\Http\Controllers\ShippingQuoteController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\AccountProfileController;
use App\Http\Controllers\LoyaltyHistoryController;
use App\Http\Controllers\CustomersContactController;
use App\Http\Controllers\ProductDepartmentController;
use App\Http\Controllers\ProductsSubSubDepartmentController;
use App\Http\Controllers\ProductSpecificationValueController;

Route::get('/test-user-broadcast', function () {


         $order = ['id' => 101, 'item' => 'Sample Product', 'amount' => 49.99];

          // Create notification
        $notification = ConxDatabaseNotification::create([
            'type' => 'order_created',
            'notifiable_type' => 'admin', // or your admin model
            'notifiable_id' => 1, // admin user ID or get dynamically
            'data' => json_encode([
                'message' => 'New order created',
                'order_id' => $order['id'],
                'user_name' => 's',
                'created_at' => now()->toDateTimeString(),
            ]),
        ]);

        // Broadcast event
        broadcast(new OrderCreated($order, $notification));

        return response()->json([
            'message' => 'Order created successfully',
            'order' => $order
        ]);
   
});



Route::get('/test-broadcast', function () {

 $admin = User::find(1);

    if ($admin) {
        // 1) save to notifications table + broadcast (Laravel style)
      $notification =  $admin->notify(new NewOrderNotification([
            'id' => 123,
            'customer_name' => 'From USER API',
            'total' => 99,
        ]));

       }

    // Broadcast to admin
    app(NotificationBroadcastService::class)->sendToAdmin($notification);

    return response()->json(['success' => true]);
});



Route::middleware('auth:api')->prefix('cart')->group(function () {
    Route::get('/', [CustomerCartController::class, 'index']);          // get DB cart
    Route::post('/sync', [CustomerCartController::class, 'sync']);   
    Route::post('/add', [CustomerCartController::class, 'add']);    // merge guest cart -> DB
    Route::post('/item', [CustomerCartController::class, 'setQuantity']);    // set qty for 1 product
    Route::delete('/item/{productId}', [CustomerCartController::class, 'remove']);
    Route::delete('/clear', [CustomerCartController::class, 'clear']);
});



Route::middleware([ForceJwtFromCookie::class, 'auth:api'])->group(function () {


       Route::get('/user', function (Request $request) {
              return response()->json([
                     'user' => Auth::guard('api')->user(),
                     'customer' => CustomersMaster::where('User_Id', Auth::guard('api')->id())->first(),
                     'data' => 'test'

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



       Route::get('/tickets',                 [SupportTicketController::class, 'index']);
       Route::post('/tickets',                 [SupportTicketController::class, 'store']);
       Route::get('/tickets/{id}',            [SupportTicketController::class, 'show']);
       Route::post('/tickets/{id}/reply',      [SupportTicketController::class, 'reply']);
       Route::patch('/tickets/{id}/close',      [SupportTicketController::class, 'close']);
       Route::delete('/tickets/{id}',            [SupportTicketController::class, 'destroy']);


       Route::prefix('/contacts')->group(function () {

              Route::get('/', [CustomersContactController::class, 'index']);
              Route::post('/', [CustomersContactController::class, 'store']);
              Route::get('/by-country/{countryId}', [CustomersContactController::class, 'byCountry']);
              Route::get('/by-state/{stateId}', [CustomersContactController::class, 'byState']);
              Route::get('/{id}', [CustomersContactController::class, 'show']);
              Route::put('/{id}', [CustomersContactController::class, 'update']);
              Route::delete('/{id}', [CustomersContactController::class, 'destroy']);
       });


       Route::put('/contacts/{id}', [CustomersContactController::class, 'update']);     // PUT
       Route::delete('/contacts/{id}', [CustomersContactController::class, 'destroy']);



       Route::controller(CustomersContactController::class)->group(function () {
              Route::get('/countries', 'countries_index');
       });



       Route::get('/account/profile', [AccountProfileController::class, 'show']);

       Route::put('/account/profile', [AccountProfileController::class, 'update']);




       Route::post('/favorites/{product}/toggle', [FavoritesController::class, 'toggle']);
       Route::get('/favorites', [FavoritesController::class, 'index']);



       Route::get('/loyalty/points', [LoyaltyHistoryController::class, 'index']);
       Route::get('/loyalty', [LoyalityController::class, 'index']);


        Route::get('/cart', [CustomerCartController::class, 'index']);
    Route::post('/cart', [CustomerCartController::class, 'addOrIncrease']);     // add item or +qty
    Route::patch('/cart', [CustomerCartController::class, 'setQuantity']);      // set qty exact
    Route::delete('/cart/{productId}', [CustomerCartController::class, 'remove']);
    Route::post('/cart/merge', [CustomerCartController::class, 'merge']);    
});


Route::post('/email/resend-link', function (Request $request) {

       // validate email input
       $request->validate([
              'email' => ['required', 'email'],
       ]);

       // find user
       $user = User::where('email', $request->email)->first();

       // We return generic success ALWAYS to avoid leaking if an email exists or not.
       // (This is standard security practice.)
       if (! $user) {
              return response()->json([
                     'message' => 'If this email is registered, a verification link was sent.',
              ], 200);
       }

       // If already verified, tell frontend it's already verified
       if ($user->Email_verified_at !== null) {
              return response()->json([
                     'message' => 'Email already verified.',
                     'already_verified' => true,
              ], 200);
       }

       // Send again using our custom notification
       $user->sendEmailVerificationNotification();

       return response()->json([
              'message' => 'Verification link sent.',
              'already_verified' => false,
       ], 200);
});



Route::get('/email/verify/{id}/{hash}', function (Request $request, $id, $hash) {
       // 1. Find the user
       $user = User::findOrFail($id);

       // 2. Hash in URL must match sha1(user email)
       $expectedHash = sha1($user->email);
       if (! hash_equals($expectedHash, (string) $hash)) {
              return redirect(config('app.frontend_url') . '/verify-error?reason=hash');
       }

       // 3. Signature / expiry validation
       if (! URL::hasValidSignature($request)) {
              return redirect(config('app.frontend_url') . '/verify-error?reason=expired');
       }

       // 4. Already verified?
       if (! is_null($user->Email_verified_at)) {
              return redirect(config('app.frontend_url') . '/already-verified');
       }

       // 5. Mark verified now
       $user->Email_verified_at = Carbon::now();
       $user->save();

       // 6. Fire event (optional, but nice for auditing / listeners)
       event(new Verified($user));

       // 7. Redirect to frontend success page
       return redirect(config('app.frontend_url') . '/verified-success');
})->name('verification.verify');



Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink']);
Route::post('/reset-password',  [PasswordResetController::class, 'resetPassword']);

Route::controller(ShippingQuoteController::class)->group(function () {
       Route::post('/v1/shipping/quotes',   'quote');
       Route::get('/shipping/getshippers', 'index');
});


Route::controller(VatController::class)->group(function () {
       Route::get('/vat', 'index');
});


Route::controller(ProductDepartmentController::class)->group(function () {
       Route::get('/productdepartment', 'index');
       Route::get('/categories/{id}/subcategories', 'getSubCategories');
       Route::get('/subcategories/{id}/subsubcategories', 'getSubSubCategories');
});


Route::controller(RegionController::class)->group(function () {
       Route::get('/region', 'index');
});



Route::controller(DistrictController::class)->group(function () {
       Route::get('/district', 'index');
});



Route::get('/countries', [GeoController::class, 'countries']);

Route::get('/regions/by-country/{countryId}', [GeoController::class, 'regionsByCountry']);
Route::get('/districts/by-region/{regionId}', [GeoController::class, 'districtsByRegion']);
Route::get('/cities/by-district/{districtId}', [GeoController::class, 'citiesByDistrict']);

Route::controller(ProductsSubSubDepartmentController::class)->group(function () {
       Route::get('/subsubdepartments/{slug}', 'index');
       Route::get('/subsubdepartments/slug/{slug}', 'slug_index');
});



Route::controller(ProductSpecificationValueController::class)->group(function () {
       Route::get('/products/value/{subsub:slug}', 'index');
});

Route::controller(ProductBrandsController::class)->group(function () {
       Route::get('/productbrand', 'index');
});

Route::controller(ProductsController::class)->group(function () {
       Route::get('/search/products', 'index');
       Route::get('/products/{subsub:slug}', 'show');
       Route::get('/products/details/{product:slug}', 'detail');
});


Route::controller(LocationsController::class)->group(function () {
              
       Route::get('/locations', 'index');
}); 

Route::controller(AuthController::class)->group(function () {
       Route::post('/register', 'register');
       Route::post('/login', 'login');
       Route::post('/logout', 'logout');
});
