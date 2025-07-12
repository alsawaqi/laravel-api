<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});


Route::get('/about', function () {
    return response()->json('This is the about page.'
     );
});


Route::get('/check-auth', function () {
    return Auth::id();
});



