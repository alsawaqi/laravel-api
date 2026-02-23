<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Tymon\JWTAuth\Contracts\JWTSubject;
use App\Notifications\VerifyEmailCustom;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;


class User extends Authenticatable implements JWTSubject, MustVerifyEmail
{
    use HasFactory, Notifiable;

    protected $table = 'Secx_User_Master_T';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $fillable = [
        'User_Id',
        'User_Name',
        'email',
        'password',
      
        'Email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];


    protected $casts = [
        'Email_verified_at' => 'datetime',
    ];




    public function getJWTIdentifier()
    {
        return $this->id;
    }

    public function getJWTCustomClaims()
    {
        return [];
    }


    public function sendEmailVerificationNotification()
    {
        $this->notify(new VerifyEmailCustom());
    }


    public function customers()
    {
        return $this->hasOne(CustomersMaster::class, 'User_Id', 'id');
    }


    public function customerOrCreate(): CustomersMaster
    {
        return $this->customers()->firstOrCreate(['User_Id' => $this->id]);
    }


     public function notifications()
    {
        return $this->morphMany(ConxDatabaseNotification::class, 'notifiable')
            ->orderBy('created_at', 'desc');
    }
}
