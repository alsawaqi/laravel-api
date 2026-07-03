<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Title extends Model
{
    protected $table = 'Titles_Master_T';

    protected $fillable = [
        'Title_Name',
        'Title_Name_Ar',
        'Is_Active',
        'Created_By',
    ];

    protected $casts = [
        'Is_Active' => 'boolean',
    ];
}
