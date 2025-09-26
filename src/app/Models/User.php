<?php
namespace App\Models;

use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements JWTSubject
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
        'updated_at'
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

  

    public function getJWTIdentifier()
    {
       return $this->id;
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

     

    public function customers()
    {
        return $this->hasOne(CustomersMaster::class, 'User_Id', 'id');
    }


    public function customerOrCreate(): CustomersMaster
    {
        return $this->customers()->firstOrCreate(['User_Id' => $this->id]);
    }
}
