<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAccountProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
         // We'll pass these in from the controller to compute proper "ignore" ids
        $currentUserId = $this->route('current_user_id');   // injected
        $customerRowId = $this->route('customer_row_id');   // injected

        return [
            'name'  => ['required', 'string', 'max:255'],
            'username' => [
                'required','string','max:255',
                Rule::unique('Secx_User_Master_T', 'User_Name')->ignore($currentUserId, 'User_Id'),
            ],
            'email' => [
                'required','email','max:255',
                Rule::unique('Secx_User_Master_T', 'email')->ignore($currentUserId, 'User_Id'),
            ],
            'phone' => [
                'required','string','max:50',
                Rule::unique('Customers_Master_T', 'Telephone')->ignore($customerRowId, 'id'),
            ],

            // password change is optional
            'current_password' => ['nullable', 'string'],
            'new_password'     => ['nullable', 'string', 'min:8', 'confirmed'], // needs new_password_confirmation
        ];
    }



       public function messages(): array
    {
        return [
            'username.unique' => 'This username is already taken.',
            'email.unique'    => 'This email is already in use.',
            'phone.unique'    => 'This phone number is already in use.',
            'new_password.confirmed' => 'New password confirmation does not match.',
        ];
    }
}
