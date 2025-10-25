<?php

use App\Mail\TestEmail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
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




Route::get('/send-test-email', function () {
    Mail::to('abdallah_644@yahoo.com')->send(new TestEmail());
    
    return 'Test email has been sent!';
});


