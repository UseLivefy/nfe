<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    protected $table = 'sales';
    
    public $timestamps = true;
    
    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        'user_id',
        'customer_id',
        'total_amount',
        'discount_amount',
        'shipping_amount',
        'final_amount',
        'payment_status',
        'payment_method',
        'payment_link',
        'payment_id',
        'qr_code',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'shipping_amount' => 'decimal:2',
        'final_amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function saleItems()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function shipping()
    {
        return $this->hasOne(Shipping::class);
    }

    public function fiscalData()
    {
        return $this->belongsTo(FiscalData::class, 'user_id', 'user_id');
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }
}
