<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'address',
        'city',
        'state',
        'zip_code',
        'phone',
        'email',
        'latitude',
        'longitude',
        'is_active',
        'opening_hours',
        'is_pickup_available',
        'is_delivery_available',
        'delivery_radius_km',
        'delivery_zone_polygon',
        'delivery_base_fee',
        'delivery_fee_per_km',
        'delivery_free_threshold',
        'delivery_min_order',
        'max_delivery_distance_km',
        'outside_geofence_fee',
        'order_value_adjustments',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'is_active' => 'boolean',
        'opening_hours' => 'array',
        'is_pickup_available' => 'boolean',
        'is_delivery_available' => 'boolean',
        'delivery_radius_km' => 'float',
        'delivery_zone_polygon' => 'array',
        'delivery_base_fee' => 'float',
        'delivery_fee_per_km' => 'float',
        'delivery_free_threshold' => 'float',
        'delivery_min_order' => 'float',
        'max_delivery_distance_km' => 'float',
        'outside_geofence_fee' => 'float',
        'order_value_adjustments' => 'array',
    ];

    /**
     * Get the orders for pickup at this location.
     */
    public function pickupOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'pickup_location_id');
    }

    /**
     * Calculate distance from a given point (in kilometers).
     *
     * @param float $latitude
     * @param float $longitude
     * @return float
     */
    public function distanceFrom(float $latitude, float $longitude): float
    {
        // Earth's radius in kilometers
        $earthRadius = 6371;
        
        $latFrom = deg2rad($this->latitude);
        $lonFrom = deg2rad($this->longitude);
        $latTo = deg2rad($latitude);
        $lonTo = deg2rad($longitude);
        
        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;
        
        $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) + 
            cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));
            
        return $angle * $earthRadius;
    }

    /**
     * Check if location is within the specified radius (in kilometers).
     *
     * @param float $latitude
     * @param float $longitude
     * @param float $radius
     * @return bool
     */
    public function isWithinRadius(float $latitude, float $longitude, float $radius = 6.0): bool
    {
        return $this->distanceFrom($latitude, $longitude) <= $radius;
    }

    /**
     * Scope a query to only include active locations.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include locations within a radius.
     */
    public function scopeNearby($query, float $latitude, float $longitude, float $radius = 6.0)
    {
        // Using the Haversine formula to calculate distance
        $haversine = "(
            6371 * acos(
                cos(radians($latitude)) 
                * cos(radians(latitude)) 
                * cos(radians(longitude) - radians($longitude)) 
                + sin(radians($latitude)) 
                * sin(radians(latitude))
            )
        )";
        
        return $query->selectRaw("*, {$haversine} AS distance")
                     ->whereRaw("{$haversine} < ?", [$radius])
                     ->orderByRaw('distance');
    }

    /**
     * Check if the location is currently open based on opening hours.
     *
     * @return bool
     */
    public function isOpenNow(): bool
    {
        if (empty($this->opening_hours)) {
            return false;
        }
        
        $dayOfWeek = strtolower(date('l')); // e.g., 'monday', 'tuesday', etc.
        $currentTime = date('H:i');
        
        // Check if the current day exists in opening hours
        if (!isset($this->opening_hours[$dayOfWeek])) {
            return false;
        }
        
        $todayHours = $this->opening_hours[$dayOfWeek];
        
        // If closed today
        if (empty($todayHours) || $todayHours === 'closed') {
            return false;
        }
        
        // If open 24 hours
        if ($todayHours === '24_hours' || $todayHours === '24hours') {
            return true;
        }
        
        // Handle multiple time slots (e.g., morning and evening hours)
        $timeSlots = is_array($todayHours) ? $todayHours : [$todayHours];
        
        foreach ($timeSlots as $timeSlot) {
            // Skip if the time slot is not properly formatted
            if (!is_string($timeSlot) || !str_contains($timeSlot, '-')) {
                continue;
            }
            
            list($openTime, $closeTime) = explode('-', $timeSlot);
            $openTime = trim($openTime);
            $closeTime = trim($closeTime);
            
            // Check if current time is within this time slot
            if ($currentTime >= $openTime && $currentTime <= $closeTime) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get formatted opening hours for display.
     *
     * @return array
     */
    public function getFormattedHoursAttribute(): array
    {
        if (empty($this->opening_hours)) {
            return [];
        }
        
        $formatted = [];
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        
        foreach ($days as $day) {
            $hours = $this->opening_hours[$day] ?? 'Closed';
            
            if ($hours === '24_hours' || $hours === '24hours') {
                $formatted[$day] = 'Open 24 Hours';
            } elseif (is_array($hours)) {
                $formatted[$day] = implode(', ', $hours);
            } else {
                $formatted[$day] = $hours;
            }
        }
        
        return $formatted;
    }
}
