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
            'User_Id' => $user->id,
            'Customer_Full_Name' => $request->name,
        ]);

        Auth::login($user); // Session login if needed
        $token = JWTAuth::fromUser($user); // Generate JWT

        DB::commit();

        return response()->json([
            'message' => 'Registered and logged in successfully.',
            'user' => $user,
            'token' => $token
        ], Response::HTTP_CREATED);

    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json(['error' => 'Transaction failed: ' . $e->getMessage()], 500);
    }
    }

    public function login(Request $request)
    {
        $credentials = $request->only('email', 'password');

        if (!$token = JWTAuth::attempt($credentials)) {
            return response()->json(['message' => 'Invalid credentials'], Response::HTTP_UNAUTHORIZED);
        }

        return response()->json([
            'message' => 'Logged in successfully.',
            'user' => Auth::user(),
            'token' => $token,
        ]);
    }

public function logout(Request $request)
{
    try {
        JWTAuth::invalidate(JWTAuth::getToken());
    } catch (\Exception $e) {
        return response()->json(['message' => 'Token invalid or already logged out.'], 400);
    }

    Auth::logout(); // optional
    return response()->json(['message' => 'Logged out successfully.']);
}

}
