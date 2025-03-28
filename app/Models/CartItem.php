<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'product_id',
        'quantity',
        'product_measurement_id',
    ];

    /**
     * Get the user that owns the cart item.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the product for the cart item.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the measurement for the cart item.
     */
    public function measurement(): BelongsTo
    {
        return $this->belongsTo(ProductMeasurement::class, 'product_measurement_id');
    }

    /**
     * Get the subtotal for the cart item.
     */
    public function getSubtotal()
    {
        $price = $this->product->getCurrentPrice();

        // Apply measurement price adjustment if applicable
        if ($this->measurement && $this->measurement->price_adjustment) {
            $price += $this->measurement->price_adjustment;
        }

        return $price * $this->quantity;
    }
}
