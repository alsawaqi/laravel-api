<?php

namespace App\Http\Controllers\Api;

use Throwable;
use App\Models\User;
use Illuminate\Http\Request;
use App\Helpers\CodeGenerator;
use App\Models\CustomersMaster;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{

    public function register(Request $request)
    {
        // 1. Validate input (same logic you had)
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',

            'username' => [
                'required',
                'string',
                'max:255',
                'unique:Secx_User_Master_T,User_Name',
                'regex:/^\S*$/u', // no spaces
            ],

            'email' => 'required|email|unique:Secx_User_Master_T,email',

            // requires "password" and "password_confirmation"
            'password' => 'required|string|min:6|confirmed',
        ]);

        try {
            if ($validator->fails()) {
                return response()->json(
                    ['errors' => $validator->errors()],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }


            DB::beginTransaction();

            // 2. Generate your custom codes
            $UserCode = CodeGenerator::createCode('USR', 'Secx_User_Master_T', 'User_Id');
            $customerCode = CodeGenerator::createCode('CUS', 'Customers_Master_T', 'Customer_Code');

            // 3. Create the user (not verified yet)
            $user = User::create([
                'User_Id'            => $UserCode,
                'User_Name'          => $request->username,
                'email'              => $request->email,
                'password'           => Hash::make($request->password),
                'Email_verified_at'  => null, // important in your schema
            ]);

            // 4. Create related customer record
            CustomersMaster::create([
                'Customer_Code'       => $customerCode,
                'Customer_Type_Id'    => 1, // default
                'User_Id'             => $user->id, // FK to users table PK
                'Customer_Full_Name'  => $request->name,
            ]);

            DB::commit();

            // 5. Tell Laravel "a new user registered"
            //    This fires the Registered event which (by default)
            //    is what triggers the email verification notification
            event(new Registered($user));

            // 6. IMPORTANT: we DO NOT log them in yet
            //    We DO NOT create JWT cookies yet
            //    We want them to click the email first

            return response()->json([
                'status'  => 'pending_verification',
                'message' => 'Account created. Please verify your email before login.',
                'user'    => [
                    'id'       => $user->id,
                    'code'     => $user->User_Id,
                    'username' => $user->User_Name,
                    'email'    => $user->email,
                ],
            ], Response::HTTP_CREATED);
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json([
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ], 500);
        }
    }

   public function login(Request $request)
{
    // 1) Validate input
    $data = $request->validate([
        'email'    => ['required', 'string'],
        'password' => ['required', 'string'],
    ]);

    // 2) Find user by email (column in DB is [email], lowercase ✅)
    $user = User::where('email', $data['email'])->first();

    if (! $user) {
        return response()->json([
            'message' => 'Invalid credentials.',
        ], Response::HTTP_UNAUTHORIZED); // 401
    }

    // 3) Check password
    if (! Hash::check($data['password'], $user->password)) {
        return response()->json([
            'message' => 'Invalid credentials.',
        ], Response::HTTP_UNAUTHORIZED); // 401
    }

    // 4) Check if verified
    if ($user->Email_verified_at === null) {
        return response()->json([
            'status'  => 'unverified',
            'message' => 'Please verify your email before logging in.',
            'email'   => $user->email, // for resend flow on frontend
        ], Response::HTTP_FORBIDDEN); // 403
    }

    // 5) User is good → issue tokens and cookies
    $accessToken = JWTAuth::fromUser($user);

    $refreshToken = JWTAuth::claims(['type' => 'refresh'])->fromUser($user);

    // Cookie settings
    // In dev, you can safely keep these null/false-ish. In prod you'll tune them.
    $domain   = config('session.domain');                     // e.g. null or ".example.com"
    $secure   = (bool) (config('session.secure', false));      // false for http://localhost, true for https
    $sameSite = strtolower(config('session.same_site', 'lax')); // "lax" usually okay for same-site
    $path     = '/';

    // Access token cookie
    $accessCookie = cookie(
        'token',
        $accessToken,
        60,        // minutes
        $path,
        $domain,
        $secure,
        true,      // httpOnly
        false,
        $sameSite
    );

    // Refresh token cookie
    $refreshCookie = cookie(
        'refresh_token',
        $refreshToken,
        60 * 24 * 7,  // 7 days in minutes
        $path,
        $domain,
        $secure,
        true,
        false,
        $sameSite
    );

    return response()
        ->json([
            'message' => 'Logged in',
            'user'    => $user,
        ])
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
