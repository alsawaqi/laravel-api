<?php

namespace App\Models;

use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;
use Illuminate\Database\Eloquent\Model;

class ProductsSubSubDepartment extends Model
{
   use HasSlug;

    protected $table = 'Products_Sub_Sub_Department_T';

     public function getSlugOptions(): SlugOptions
      {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug');
     }

    public function getRouteKeyName()
     {
        return 'slug';
     }


}
