<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Notifications\ResetPasswordCustom;

class PasswordResetController extends Controller
{
  public function sendResetLink(Request $request)
    {
        // 1. Validate email input
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        // 2. Find the user in your users table
        $user = User::where('email', $request->email)->first();

        // --- SECURITY NOTE ---
        // We will ALWAYS return the same response, even if user not found.
        // But if user exists, we will actually generate/send the email.
        if ($user) {

            // 3. Create a brand new plain token (this is what we send by email)
            $plainToken = Str::random(64);

            // 4. Hash it for storage
            $hashedToken = Hash::make($plainToken);

            // 5. Clean old tokens for this email (optional but good practice)
            DB::table('Security_Password_Reset_Tokens_T')
                ->where('email', $user->email)
                ->delete();

            // 6. Insert new reset row into YOUR table
            DB::table('Security_Password_Reset_Tokens_T')->insert([
                'email'      => $user->email,
                'token'      => $hashedToken,
                'created_at' => Carbon::now(),
            ]);

            // 7. Build the reset URL for the FRONTEND, not the API
            //
            // Example:
            // http://localhost:84/reset-password?token=...&email=...
            //
            // On production this will be like https://shop.yourdomain.com/reset-password?...etc
            //
            $frontend = config('app.frontend_url'); // e.g. http://localhost:84
            $resetUrl = $frontend
                      . '/reset-password'
                      . '?token=' . urlencode($plainToken)
                      . '&email=' . urlencode($user->email);

            // 8. Send the email using our custom notification
            $user->notify(new ResetPasswordCustom($resetUrl));
        }

        // 9. Always generic response
        return response()->json([
            'message' => 'If an account exists, a password reset link has been sent to that email.',
        ], 200);
    }



     public function resetPassword(Request $request)
    {
        // 1. validate
        $request->validate([
            'email'                 => ['required', 'email'],
            'token'                 => ['required', 'string'],
            'password'              => ['required', 'string', 'min:6', 'confirmed'],
            // NOTE: "confirmed" means frontend must send password_confirmation too
        ]);

        $email      = $request->input('email');
        $plainToken = $request->input('token');
        $newPass    = $request->input('password');

        // 2. find reset row
        $resetRow = DB::table('Security_Password_Reset_Tokens_T')
            ->where('email', $email)
            ->first();

        if (! $resetRow) {
            return response()->json([
                'message' => 'Invalid or expired reset token.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // 3. check token age (example: 60 minutes expiry)
        $createdAt = Carbon::parse($resetRow->created_at);
        if ($createdAt->lt(Carbon::now()->subMinutes(60))) {
            // token too old → delete it
            DB::table('Security_Password_Reset_Tokens_T')
                ->where('email', $email)
                ->delete();

            return response()->json([
                'message' => 'Reset link has expired. Please request a new one.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // 4. compare the provided plain token with the hashed token in DB
        $isValidToken = Hash::check($plainToken, $resetRow->token);
        if (! $isValidToken) {
            return response()->json([
                'message' => 'Invalid reset token.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // 5. find the user by email
        $user = User::where('email', $email)->first();
        if (! $user) {
            // strange case: token exists but user not found
            return response()->json([
                'message' => 'User not found.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // 6. update password
        $user->password = Hash::make($newPass);
        $user->save();

        // 7. delete the token row so it cannot be reused
        DB::table('Security_Password_Reset_Tokens_T')
            ->where('email', $email)
            ->delete();

        return response()->json([
            'message' => 'Password has been reset successfully. You can now log in.',
        ], 200);
    }
}
