<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Carbon\Carbon;

class Coupon extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'code',
        'type',
        'value',
        'min_order_amount',
        'max_discount_amount',
        'starts_at',
        'expires_at',
        'usage_limit',
        'used_count',
        'is_active',
        'description',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'value' => 'decimal:2',
        'min_order_amount' => 'decimal:2',
        'max_discount_amount' => 'decimal:2',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * The coupon types.
     */
    const TYPE_FIXED = 'fixed';
    const TYPE_PERCENTAGE = 'percentage';

    /**
     * Get the orders that used this coupon.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get the categories this coupon applies to.
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    /**
     * Get the products this coupon applies to.
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }

    /**
     * Check if the coupon is valid.
     *
     * @param float $orderAmount
     * @param int $userId
     * @return bool
     */
    public function isValid(float $orderAmount, int $userId = null): bool
    {
        // Check if coupon is active
        if (!$this->is_active) {
            return false;
        }

        // Check if coupon has started
        if ($this->starts_at && Carbon::now()->lt($this->starts_at)) {
            return false;
        }

        // Check if coupon has expired
        if ($this->expires_at && Carbon::now()->gt($this->expires_at)) {
            return false;
        }

        // Check if coupon has reached usage limit
        if ($this->usage_limit && $this->used_count >= $this->usage_limit) {
            return false;
        }

        // Check if order meets minimum amount
        if ($this->min_order_amount && $orderAmount < $this->min_order_amount) {
            return false;
        }

        // Check if user has already used this coupon (if user-specific limit exists)
        if ($userId && $this->hasUserUsedCoupon($userId)) {
            return false;
        }

        return true;
    }

    /**
     * Calculate the discount amount for an order.
     *
     * @param float $orderAmount
     * @return float
     */
    public function calculateDiscount(float $orderAmount): float
    {
        if ($this->type === self::TYPE_FIXED) {
            return min($this->value, $orderAmount);
        }

        if ($this->type === self::TYPE_PERCENTAGE) {
            $discount = ($orderAmount * $this->value) / 100;
            
            // Apply maximum discount if set
            if ($this->max_discount_amount) {
                return min($discount, $this->max_discount_amount);
            }
            
            return $discount;
        }

        return 0;
    }

    /**
     * Check if a user has already used this coupon.
     *
     * @param int $userId
     * @return bool
     */
    protected function hasUserUsedCoupon(int $userId): bool
    {
        return $this->orders()
            ->where('user_id', $userId)
            ->where('payment_status', Order::PAYMENT_PAID)
            ->exists();
    }

    /**
     * Scope a query to only include active coupons.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include valid coupons.
     */
    public function scopeValid($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', Carbon::now());
            })
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', Carbon::now());
            })
            ->where(function ($q) {
                $q->whereNull('usage_limit')
                    ->orWhereRaw('used_count < usage_limit');
            });
    }
}
