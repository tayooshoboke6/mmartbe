<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeliverySettings extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'is_global',
        'base_fee',
        'fee_per_km',
        'free_threshold',
        'min_order',
        'max_distance',
        'is_active',
        'store_id'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_global' => 'boolean',
        'base_fee' => 'integer',
        'fee_per_km' => 'integer',
        'free_threshold' => 'integer',
        'min_order' => 'integer',
        'max_distance' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Get the store that owns the delivery settings.
     */
    public function store()
    {
        return $this->belongsTo(StoreAddress::class, 'store_id');
    }
}
