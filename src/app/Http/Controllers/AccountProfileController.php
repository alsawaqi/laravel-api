<?php

namespace App\Http\Controllers;

 
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

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
            'name' => (string)($customer->Customer_Full_Name ?? ''),
            'username' => (string)($userRow->User_Name ?? ''),
            'email' => (string)($userRow->email ?? ''),
            'phone_country_code' => (string)($customer->Telephone_Country_Code ?? '+968'),
            'phone' => (string)($customer->Telephone ?? ''),
            'avatar_path' => $customer->Customer_Profile_Image_Path ?? null,
            'avatar_url' => !empty($customer->Customer_Profile_Image_Path)
                ? Storage::disk('uploads')->url($customer->Customer_Profile_Image_Path)
                : null,
        ]);
    }



  public function update(Request $request)
    {
        $input = $request->input('payload', $request->except('avatar'));
        if (is_string($input)) {
            $decoded = json_decode($input, true);
            $input = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($input)) {
            $input = [];
        }

        if (array_key_exists('new_password', $input) && !array_key_exists('newPassword', $input)) {
            $input['newPassword'] = $input['new_password'];
        }
        if (array_key_exists('current_password', $input) && !array_key_exists('currentPassword', $input)) {
            $input['currentPassword'] = $input['current_password'];
        }

        $validationData = $input;
        if ($request->hasFile('avatar')) {
            $validationData['avatar'] = $request->file('avatar');
        }

        $validator = Validator::make($validationData, [
            'name' => ['required', 'string', 'max:150'],
            'username' => ['nullable', 'string', 'max:150', 'regex:/^\S*$/'],
            'email' => ['required', 'email', 'max:150'],
            'phone_country_code' => ['nullable', 'string', 'max:12', 'regex:/^\+\d{1,4}$/'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^\d+$/'],
            'currentPassword' => ['nullable', 'string'],
            'newPassword' => ['nullable', 'string', 'min:8'],
            'remove_avatar' => ['nullable', 'boolean'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:300'],
        ], [
            'phone.regex' => 'The phone number may contain numbers only.',
            'phone_country_code.regex' => 'Choose a valid phone country code.',
            'avatar.max' => 'The profile image must not be larger than 300KB.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();

        try {

            $response = DB::transaction(function () use ($request, $validated) {
           
            $userQuery = DB::table('Secx_User_Master_T')->where('id', Auth::id() ?? 0);
            if (!$userQuery->exists()) {
                $userQuery = DB::table('Secx_User_Master_T')->where('id', Auth::id() ?? 0);
            }

            $userRow = $userQuery->first();
            if (!$userRow) {
                abort(404, 'User profile not found.');
            }

            $secxUpdate = [];
            if (array_key_exists('username', $validated)) {
                $secxUpdate['User_Name'] = trim((string) $validated['username']);
            }
            if (array_key_exists('email', $validated)) {
                $secxUpdate['email'] = trim((string) $validated['email']);
            }
            if (!empty($validated['newPassword'] ?? null)) {
                if (empty($validated['currentPassword'] ?? null) || !Hash::check((string) $validated['currentPassword'], (string) $userRow->password)) {
                    return response()->json([
                        'errors' => [
                            'currentPassword' => ['The current password is incorrect.'],
                        ],
                    ], 422);
                }

                $secxUpdate['password'] = Hash::make((string) $validated['newPassword']);
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
                if (array_key_exists('name', $validated)) {
                    $custUpdate['Customer_Full_Name'] = trim((string) $validated['name']);
                }
                if (Schema::hasColumn('Customers_Master_T', 'Telephone_Country_Code') && array_key_exists('phone_country_code', $validated)) {
                    $custUpdate['Telephone_Country_Code'] = $validated['phone_country_code'] ?: null;
                }
                if (array_key_exists('phone', $validated)) {
                    $custUpdate['Telephone'] = trim((string) $validated['phone']);
                }
                // Optional: keep email in sync on customer record too
                if (array_key_exists('email', $validated)) {
                    $custUpdate['Email_Address'] = trim((string) $validated['email']);
                }

                $oldAvatar = $customer->Customer_Profile_Image_Path ?? null;
                if (!empty($validated['remove_avatar']) && Schema::hasColumn('Customers_Master_T', 'Customer_Profile_Image_Path')) {
                    if ($oldAvatar) {
                        Storage::disk('uploads')->delete($oldAvatar);
                    }

                    $custUpdate['Customer_Profile_Image_Path'] = null;
                    $custUpdate['Customer_Profile_Image_Size'] = null;
                    $custUpdate['Customer_Profile_Image_Extension'] = null;
                    $custUpdate['Customer_Profile_Image_Type'] = null;
                }

                if ($request->hasFile('avatar') && Schema::hasColumn('Customers_Master_T', 'Customer_Profile_Image_Path')) {
                    if ($oldAvatar) {
                        Storage::disk('uploads')->delete($oldAvatar);
                    }

                    $file = $request->file('avatar');
                    $path = Storage::disk('uploads')->putFile('customers/profile', $file, 'public');

                    $custUpdate['Customer_Profile_Image_Path'] = $path;
                    $custUpdate['Customer_Profile_Image_Size'] = $file->getSize();
                    $custUpdate['Customer_Profile_Image_Extension'] = $file->getClientOriginalExtension();
                    $custUpdate['Customer_Profile_Image_Type'] = $file->getMimeType();
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
                    'username' => $validated['username'] ?? null,
                    'email' => $validated['email'] ?? null,
                    'name' => $validated['name'] ?? null,
                    'phone_country_code' => $validated['phone_country_code'] ?? null,
                    'phone' => $validated['phone'] ?? null,
                ],
            ]);
        });

            return $response;
          } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while updating the profile.'], 500);
        }

      


 
    }

    
}
