<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemParameterUiSlider extends Model
{
    protected $table = 'System_Parameter_UI_Sliders_T';
    protected $guarded = [];

    protected $casts = [
        'Is_Active' => 'boolean',
        'Active_From' => 'datetime',
        'Active_To' => 'datetime',
    ];
}