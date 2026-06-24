<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\State;
use App\Models\Country;
use Illuminate\Http\Request;
use App\Helpers\CodeGenerator;
use App\Models\CustomersContact;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CustomersContactController extends Controller
{
    //



     public function countries_index()
    {
        return response()->json(
             
            Country::latest()->get()
        );
    }

    public function index()
        {
            $contacts = CustomersContact::with(['country', 'region', 'district', 'city'])
                                          ->where('Customers_Contact_Id', Auth::user()?->customers->id)
                                          ->when(Schema::hasColumn('Customers_Contact_T', 'Is_Default'), function ($query) {
                                              $query->orderByDesc('Is_Default');
                                          })
                                          ->latest()
                                          ->get();
            return response()->json($contacts);
        }


        public function show($id)
            {
                $contact = CustomersContact::with(['country', 'state', 'district','region', 'city'])
                                            ->where('Customers_Contact_Id', Auth::user()?->customers->id)
                                           ->findOrFail($id);
                return response()->json($contact);
            }



    public function store(Request $request)
    {
        $request->validate([
            'Telephone_Country_Code' => ['nullable', 'string', 'max:12', 'regex:/^\+\d{1,4}$/'],
            'Telephone' => ['nullable', 'string', 'max:30', 'regex:/^\d+$/'],
        ], [
            'Telephone_Country_Code.regex' => 'Choose a valid phone country code.',
            'Telephone.regex' => 'The telephone number may contain numbers only.',
        ]);


         try{
                     $customer = Auth::user()?->customers;

                     $isFirstContact = !CustomersContact::where('Customers_Contact_Id', $customer->id)->exists();

                     $contactData = [
                        'Customer_Contact_Code' => CodeGenerator::createCode('ADDR', 'Customers_Contact_T', 'Customer_Contact_Code'),
                        'Customers_Contact_Id' => $customer->id,
                        'Type' => $request->Type,
                        'Country_Id' => $request->Country_Id,
                       
                        'City_Id' => $request->City_Id,
                        'Contact_Person_Name' => $request->Contact_Person_Name,
                        'Telephone_Country_Code' => $request->Telephone_Country_Code,
                        'Telephone' => $request->Telephone,
                        'Fax' => $request->Fax,
                        'Gsm' => $request->Gsm,
                        'Email' => $request->Email,
                        'Designation' => $request->Designation,
                        'Remarks' => $request->Remarks,
                        'Created_date' => now(),
                        'Region_Id' => $request->Region_Id,
                        'District_Id' => $request->District_Id,
                    
                    ];

                    if (Schema::hasColumn('Customers_Contact_T', 'Is_Default')) {
                        $contactData['Is_Default'] = $isFirstContact;
                    }

                    $contact = CustomersContact::create($contactData);

                    return response()->json([
                        'message' => 'Address saved successfully.',
                        'data' => $contact->load(['country', 'state', 'city']),
                    ], 201);

            }catch (\Exception $e) {
                return response()->json(['message' => 'Error saving contact: ' . $e->getMessage()], 500);
            }


       
    }
    



        public function byCountry($id)
        {
            return State::where('Country_Id', $id)->get();
        }



    public function byState($id)
    {
        return City::where('District_Id', $id)->get();
    }


    public function update(Request $request, int $id)
    {
        $customer = Auth::user()?->customers;
        if (!$customer) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $contact = CustomersContact::where('id', $id)
            ->where('Customers_Contact_Id', $customer->id)
            ->firstOrFail();

        $request->validate([
            'Telephone_Country_Code' => ['nullable', 'string', 'max:12', 'regex:/^\+\d{1,4}$/'],
            'Telephone' => ['nullable', 'string', 'max:30', 'regex:/^\d+$/'],
        ], [
            'Telephone_Country_Code.regex' => 'Choose a valid phone country code.',
            'Telephone.regex' => 'The telephone number may contain numbers only.',
        ]);

        // Update directly from request (no validated array)
        $updatable = [
            'Type',
            'Country_Id',
            'Region_Id',
            'District_Id',
            'City_Id',
            'Contact_Person_Name',
            'Telephone_Country_Code',
            'Telephone',
            'Fax',
            'Gsm',
            'Email',
            'Designation',
            'Remarks',
        ];

        $data = $request->only($updatable);
        $contact->fill($data)->save();

        return response()->json([
            'message' => 'Address updated.',
            'data'    => $contact->load(['country', 'region', 'district', 'city']),
        ]);
    }

    // DELETE /api/contacts/{id}
    public function destroy(Request $request, int $id)
    {
        $customer = Auth::user()?->customers;
        if (!$customer) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $contact = CustomersContact::where('id', $id)
            ->where('Customers_Contact_Id', $customer->id)
            ->firstOrFail();

        $wasDefault = (bool)($contact->Is_Default ?? false);
        $contact->delete();

        if ($wasDefault && Schema::hasColumn('Customers_Contact_T', 'Is_Default')) {
            $nextDefault = CustomersContact::where('Customers_Contact_Id', $customer->id)
                ->latest()
                ->first();

            if ($nextDefault) {
                $nextDefault->forceFill(['Is_Default' => true])->save();
            }
        }

        return response()->json(['message' => 'Address deleted.']);
    }

    public function setDefault(Request $request, int $id)
    {
        $customer = Auth::user()?->customers;
        if (!$customer) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if (!Schema::hasColumn('Customers_Contact_T', 'Is_Default')) {
            return response()->json(['message' => 'Address default flag is not migrated yet.'], 422);
        }

        $contact = CustomersContact::where('id', $id)
            ->where('Customers_Contact_Id', $customer->id)
            ->firstOrFail();

        DB::transaction(function () use ($customer, $contact) {
            CustomersContact::where('Customers_Contact_Id', $customer->id)
                ->update(['Is_Default' => false]);

            $contact->forceFill(['Is_Default' => true])->save();
        });

        return response()->json([
            'message' => 'Default address updated.',
            'data' => $contact->fresh()->load(['country', 'region', 'district', 'city']),
        ]);
    }


}
