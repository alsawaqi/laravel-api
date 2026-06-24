<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ConxDatabaseNotification extends Model
{
    protected $table = 'Conx_Notifications_T';

    // Either use guarded OR fillable, not both. This means "everything fillable":
    protected $guarded = [];

    // If you want to keep fillable, remove guarded or set guarded=[] and remove fillable.
    // protected $fillable = [
    //     'type',
    //     'notifiable_type',
    //     'notifiable_id',
    //     'data',
    //     'read_at',
    // ];

    protected $casts = [
        'data'    => 'array',
        'read_at' => 'datetime',
    ];

    public $timestamps = true;

    // Tell Eloquent: primary key is NOT auto-increment int
    public $incrementing = false;
    protected $keyType   = 'string';

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            // If no id was set, generate a UUID
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }

    public function setDataAttribute($value): void
    {
        if (is_array($value)) {
            $this->attributes['data'] = json_encode($value);
        } else {
            $this->attributes['data'] = $value;
        }
    }
}
