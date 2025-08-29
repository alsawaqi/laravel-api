<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\State;
use App\Models\Country;
use Illuminate\Http\Request;
use App\Helpers\CodeGenerator;
use App\Models\CustomersContact;
use Illuminate\Support\Facades\Auth;

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
            'Telephone' => 'nullable|string|max:50',
        ]);


         try{
                     $customer = Auth::user()?->customers;

                     $contact = CustomersContact::create([
                        'Customer_Contact_Code' => CodeGenerator::createCode('ADDR', 'Customers_Contact_T', 'Customer_Contact_Code'),
                        'Customers_Contact_Id' => $customer->id,
                        'Type' => $request->Type,
                        'Country_Id' => $request->Country_Id,
                       
                        'City_Id' => $request->City_Id,
                        'Contact_Person_Name' => $request->Contact_Person_Name,
                        'Telephone' => $request->Telephone,
                        'Fax' => $request->Fax,
                        'Gsm' => $request->Gsm,
                        'Email' => $request->Email,
                        'Designation' => $request->Designation,
                        'Remarks' => $request->Remarks,
                        'Created_date' => now(),
                        'Region_Id' => $request->Region_Id,
                        'District_Id' => $request->District_Id,
                    
                    ]);

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

        // Update directly from request (no validated array)
        $updatable = [
            'Type',
            'Country_Id',
            'Region_Id',
            'District_Id',
            'City_Id',
            'Contact_Person_Name',
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

        $contact->delete();

        return response()->json(['message' => 'Address deleted.']);
    }


}
