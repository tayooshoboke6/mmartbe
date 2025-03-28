<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductMeasurement extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'product_id',
        'unit',
        'value',
        'price',
        'sale_price',
        'stock_quantity',
        'sku',
        'is_default',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];
    
    /**
     * The "booted" method of the model.
     *
     * @return void
     */
    protected static function booted()
    {
        // When a measurement is created, updated, or deleted, sync the product stock
        static::saved(function ($measurement) {
            $measurement->product->syncStockWithMeasurements();
        });
        
        static::deleted(function ($measurement) {
            $measurement->product->syncStockWithMeasurements();
        });
    }

    /**
     * Get the product that owns the measurement.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
