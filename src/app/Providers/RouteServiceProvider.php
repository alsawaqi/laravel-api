<?php

namespace App\Providers;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\ForceJwtFromCookie;
use Illuminate\Support\ServiceProvider;

class RouteServiceProvider extends ServiceProvider
{
   public function boot(): void
{
    Route::middleware('api')
        ->prefix('api')
        ->group(function () {
            Route::middleware([ForceJwtFromCookie::class])
                ->group(base_path('routes/api.php'));
        });
}
}
