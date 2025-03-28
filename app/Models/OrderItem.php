<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'order_id',
        'product_id',
        'product_name',
        'quantity',
        'unit_price',
        'base_price',
        'subtotal',
        'product_measurement_id',
        'measurement_unit',
        'measurement_value',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'unit_price' => 'decimal:2',
    ];

    /**
     * Get the order that owns the item.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the product for the order item.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the measurement for the order item.
     */
    public function measurement(): BelongsTo
    {
        return $this->belongsTo(ProductMeasurement::class, 'product_measurement_id');
    }

    /**
     * Get the subtotal for the order item.
     */
    public function getSubtotal(): float
    {
        return $this->unit_price * $this->quantity;
    }
}
