<?php

namespace App\Http\Controllers;

 
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Http\Requests\UpdateAccountProfileRequest;
 

class AccountProfileController extends Controller
{
    

     public function show(Request $request)
    {
        $authUserId = Auth::id();

        // Fetch both rows by the same User_Id link
        $userRow = DB::table('Secx_User_Master_T')->where('id', $authUserId)->first();
        $customer = DB::table('Customers_Master_T')->where('User_Id', $authUserId)->first();

        if (!$userRow || !$customer) {
            return response()->json(['message' => 'Profile not found'], 404);
        }

        return response()->json([
            'name'     => (string)($customer->Customer_Full_Name ?? ''),
            'username' => (string)($userRow->User_Name ?? ''),
            'email'    => (string)($userRow->email ?? ''),
            'phone'    => (string)($customer->Telephone ?? ''),
        ]);
    }



  public function update(Request $request)
    {

  
  
        //  Unwrap if client sent { payload: {...} } (else use flat body)
        $input = $request->input('payload', $request->all());

        try {

              DB::transaction(function () use ($input) {
           
            $userQuery = DB::table('Secx_User_Master_T')->where('id', Auth::id() ?? 0);
            if (!$userQuery->exists()) {
                $userQuery = DB::table('Secx_User_Master_T')->where('id', Auth::id() ?? 0);
            }

            $secxUpdate = [];
            if (array_key_exists('username', $input)) {
                $secxUpdate['User_Name'] = trim((string) $input['username']);
            }
            if (array_key_exists('email', $input)) {
                $secxUpdate['email'] = trim((string) $input['email']);
            }
            if (!empty($input['newPassword'] ?? null)) {
                $secxUpdate['password'] = Hash::make((string) $input['newPassword']);
                $secxUpdate['Password_Changed_Date'] = now();
            }
            if (!empty($secxUpdate)) {
                $secxUpdate['updated_at'] = now();
                $userQuery->update($secxUpdate);
            }

            // --- Update Customers_Master_T (full name, phone, optional email sync) ---
            $customer = DB::table('Customers_Master_T')
                ->where('User_Id', Auth::id() ?? 0)
                ->first();

            if ($customer) {
                $custUpdate = [];
                if (array_key_exists('name', $input)) {
                    $custUpdate['Customer_Full_Name'] = trim((string) $input['name']);
                }
                if (array_key_exists('phone', $input)) {
                    $custUpdate['Telephone'] = trim((string) $input['phone']);
                }
                // Optional: keep email in sync on customer record too
                if (array_key_exists('email', $input)) {
                    $custUpdate['Email_Address'] = trim((string) $input['email']);
                }

                if (!empty($custUpdate)) {
                    $custUpdate['updated_at'] = now();
                    DB::table('Customers_Master_T')
                        ->where('id', $customer->id)
                        ->update($custUpdate);
                }
            }

            return response()->json([
                'message' => 'Profile updated.',
                'data' => [
                    'username' => $input['username'] ?? null,
                    'email'    => $input['email'] ?? null,
                    'name'     => $input['name'] ?? null,
                    'phone'    => $input['phone'] ?? null,
                ],
            ]);
        });
          } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while updating the profile.'], 500);
        }

      


 
    }

    
}
