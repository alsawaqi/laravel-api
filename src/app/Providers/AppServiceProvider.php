<?php

namespace App\Providers;

 
use Laravel\Sanctum\Sanctum;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Http\Parser\Cookies;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {

    $parser = JWTAuth::parser();
    $parser->addParser(new Cookies(true));
     
    }
}
