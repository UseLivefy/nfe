<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $table = 'customers';
    
    public $timestamps = true;

    protected $fillable = [
        'user_id',
        'name',
        'email',
        'phone',
        'document',
    ];

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function addresses()
    {
        return $this->hasMany(ShippingAddress::class);
    }
}
