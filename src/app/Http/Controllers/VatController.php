<?php

namespace App\Http\Controllers;

use App\Models\Vat;
use Illuminate\Http\Request;

class VatController extends Controller
{
    //

   public function index()
   {
      $vat = Vat::select('Vat')->first();     
 
        return response()->json([
            'vat' => $vat->Vat,
        ]);
   }

}
