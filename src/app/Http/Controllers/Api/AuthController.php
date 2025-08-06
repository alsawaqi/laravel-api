<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\Request;
use App\Helpers\CodeGenerator;
use App\Models\CustomersMaster;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
  
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:Secx_User_Master_T,User_Name|regex:/^\S*$/u',
            'email' => 'required|email|unique:Secx_User_Master_T,email',
            'password' => 'required|string|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            DB::beginTransaction();

            $UserCode = CodeGenerator::createCode('USR', 'Secx_User_Master_T', 'User_Id');

            $user = User::create([
                'User_Id' => $UserCode,
                'User_Name' => $request->username,
                'email' => $request->email,
                'password' => Hash::make($request->password),
            ]);

            $customerCode = CodeGenerator::createCode('CUS', 'Customers_Master_T', 'Customer_Code');

            CustomersMaster::create([
                'Customer_Code' => $customerCode,
                'Customer_Type_Id'=> 1, // Default type
                'User_Id' => $user->id,
                'Customer_Full_Name' => $request->name,
            ]);

            DB::commit();

            // 🔐 Issue access and refresh tokens
            $accessToken = JWTAuth::fromUser($user);
            $refreshToken = JWTAuth::claims(['type' => 'refresh'])->fromUser($user);

            // 🍪 Set cookies
            $accessCookie = cookie('token', $accessToken, 60, '/', null, false, true, false, 'Lax');
            $refreshCookie = cookie('refresh_token', $refreshToken, 60 * 24 * 7, '/', null, false, true, false, 'Lax');

           return response([
                        'message' => 'Registered and logged in successfully.',
                        'user' => $user,
                        'token' => $accessToken // 🔁 include token in response too
                    ], Response::HTTP_CREATED)
                    ->withCookie($accessCookie)
                    ->withCookie($refreshCookie);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Transaction failed: ' . $e->getMessage()], 500);
        }
    }

    public function login(Request $request)
    {
                    // Map the input manually
                    $credentials = [
                        'email' => $request->email,
                        'password' => $request->password,
                    ];

                    if (!$accessToken= JWTAuth::attempt($credentials)) {
                        return response()->json(['message' => 'Invalid credentials'], Response::HTTP_UNAUTHORIZED);
                    }


                      $refreshToken = JWTAuth::claims(['type' => 'refresh'])->fromUser(Auth::user());

                      $accessCookie = cookie('token', $accessToken, 60, '/', null, false, true, false, 'Lax');
                      $refreshCookie = cookie('refresh_token', $refreshToken, 60 * 24 * 7, '/', null, false, true, false, 'Lax');

                 return response()->json(['message' => 'Logged in', 'user' => Auth::user()])
                                        ->withCookie($accessCookie)
                                        ->withCookie($refreshCookie);

                  
    }

    public function logout(Request $request)
    {
           
        try {
                // Invalidate the token
                JWTAuth::invalidate(JWTAuth::getToken());
            } catch (\Exception $e) {
                return response()->json(['message' => 'Token invalid or already logged out.'], 400);
            }

            Auth::logout(); // Optional — in case of session-based login too

            // 🍪 Forget both access and refresh token cookies
            $forgetAccess = cookie()->forget('token');
            $forgetRefresh = cookie()->forget('refresh_token');

            return response()->json(['message' => 'Logged out successfully.'])
                            ->withCookies([$forgetAccess, $forgetRefresh]);
    }


}
