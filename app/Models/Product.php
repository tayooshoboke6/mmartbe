<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'short_description',
        'base_price',
        'sale_price',
        'is_featured',
        'is_new_arrival',
        'is_active',
        'stock_quantity',
        'total_sold',
        'sku',
        'barcode',
        'category_id',
        'brand',
        'expiry_date',
        'meta_data',
        'is_hot_deal',
        'is_best_seller',
        'is_expiring_soon',
        'is_clearance',
        'is_recommended',
        'image_url',
        'weight',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'base_price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'is_featured' => 'boolean',
        'is_new_arrival' => 'boolean',
        'is_active' => 'boolean',
        'expiry_date' => 'date',
        'meta_data' => 'json',
        'is_hot_deal' => 'boolean',
        'is_best_seller' => 'boolean',
        'is_expiring_soon' => 'boolean',
        'is_clearance' => 'boolean',
        'is_recommended' => 'boolean',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = [
        'is_on_sale',
        'is_in_stock',
        'discount_percentage',
        'image_url',
    ];

    /**
     * Get the category that owns the product.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get the measurements for the product.
     */
    public function measurements(): HasMany
    {
        return $this->hasMany(ProductMeasurement::class);
    }
    
    /**
     * Get the images for the product.
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }
    
    /**
     * Check if the product has enough stock for the given quantity.
     * If the product has measurements, it will check the stock of the specified measurement.
     * Otherwise, it will check the product's stock.
     *
     * @param int $quantity
     * @param int|null $measurementId
     * @return bool
     */
    public function hasEnoughStock(int $quantity, ?int $measurementId = null): bool
    {
        if ($measurementId) {
            $measurement = $this->measurements()->where('id', $measurementId)->first();
            return $measurement && $measurement->stock_quantity >= $quantity;
        }
        
        return $this->stock_quantity >= $quantity;
    }
    
    /**
     * Get the available stock for the product.
     * If a measurement ID is provided, it returns the stock for that measurement.
     * Otherwise, it returns the product's stock.
     *
     * @param int|null $measurementId
     * @return int
     */
    public function getAvailableStock(?int $measurementId = null): int
    {
        if ($measurementId) {
            $measurement = $this->measurements()->where('id', $measurementId)->first();
            return $measurement ? $measurement->stock_quantity : 0;
        }
        
        return $this->stock_quantity;
    }

    /**
     * Determine if the product is on sale.
     *
     * @return bool
     */
    public function getIsOnSaleAttribute(): bool
    {
        return $this->sale_price !== null && $this->sale_price < $this->base_price;
    }

    /**
     * Determine if the product is in stock.
     *
     * @return bool
     */
    public function getIsInStockAttribute(): bool
    {
        return $this->stock_quantity > 0;
    }

    /**
     * Calculate the discount percentage if the product is on sale.
     *
     * @return float|null
     */
    public function getDiscountPercentageAttribute(): ?float
    {
        if ($this->is_on_sale && $this->base_price > 0) {
            return round((($this->base_price - $this->sale_price) / $this->base_price) * 100);
        }
        
        return null;
    }

    /**
     * Get the primary image URL for the product.
     *
     * @return string|null
     */
    public function getImageUrlAttribute(): ?string
    {
        $primaryImage = $this->images()->where('is_primary', true)->first();
        
        if ($primaryImage) {
            return $primaryImage->image_path;
        }
        
        // If no primary image, return the first image
        $firstImage = $this->images()->first();
        
        return $firstImage ? $firstImage->image_path : null;
    }

    /**
     * Sync the product's stock_quantity with the sum of all its measurements' stock.
     * This should be called after updating measurement stock to keep the product stock in sync.
     *
     * @return void
     */
    public function syncStockWithMeasurements(): void
    {
        // Only sync if the product has measurements
        if ($this->measurements()->count() > 0) {
            $totalStock = $this->measurements()->sum('stock_quantity');
            $this->update(['stock_quantity' => $totalStock]);
        }
    }

    /**
     * Get the current price of the product.
     * Returns the sale_price if available, otherwise returns the base_price.
     *
     * @return float
     */
    public function getCurrentPrice(): float
    {
        return $this->sale_price > 0 ? $this->sale_price : $this->base_price;
    }
}
