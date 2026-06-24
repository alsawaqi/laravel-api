<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
 

class ForceJwtFromCookie
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->hasCookie('token')) {
            $token = $request->cookie('token');

            try {
                $user = JWTAuth::setToken($token)->authenticate();
                Auth::guard('api')->setUser($user);
            } catch (\Exception $e) {
                Log::warning('[JWT] Failed to authenticate from cookie: ' . $e->getMessage());
            }
        }

        return $next($request);
    }
}

