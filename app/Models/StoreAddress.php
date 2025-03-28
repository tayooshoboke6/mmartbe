<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreAddress extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'address_line1',
        'address_line2',
        'formatted_address',
        'city',
        'state',
        'postal_code',
        'country',
        'phone',
        'email',
        'latitude',
        'longitude',
        'is_pickup_location',
        'is_delivery_location',
        'is_active',
        'opening_hours',
        'notes',
        'delivery_base_fee',
        'delivery_fee_per_km',
        'free_delivery_threshold',
        'minimum_order_value',
        'offers_free_delivery',
        'delivery_radius_km',
        'geofence_coordinates'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'is_pickup_location' => 'boolean',
        'is_delivery_location' => 'boolean',
        'is_active' => 'boolean',
        'offers_free_delivery' => 'boolean',
        'delivery_base_fee' => 'float',
        'delivery_fee_per_km' => 'float',
        'free_delivery_threshold' => 'float',
        'delivery_radius_km' => 'integer'
    ];

    /**
     * Get the full address as a string.
     *
     * @return string
     */
    public function getFullAddressAttribute()
    {
        $parts = [
            $this->address_line1,
            $this->address_line2,
            $this->city,
            $this->state,
            $this->postal_code,
            $this->country
        ];

        return implode(', ', array_filter($parts));
    }

    /**
     * Calculate distance from a given latitude and longitude (in kilometers).
     *
     * @param float $latitude
     * @param float $longitude
     * @return float|null
     */
    public function distanceFrom($latitude, $longitude)
    {
        if (!$this->latitude || !$this->longitude) {
            return null;
        }

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
     * Check if a point is within this store's geofence.
     *
     * @param float $latitude
     * @param float $longitude
     * @return bool
     */
    public function isPointInGeofence($latitude, $longitude)
    {
        if (!$this->geofence_coordinates || !$this->is_delivery_location) {
            return false;
        }

        try {
            $polygon = json_decode($this->geofence_coordinates, true);
            if (!is_array($polygon) || empty($polygon)) {
                \Log::error('Invalid geofence coordinates for store ' . $this->id);
                return false;
            }

            \Log::info('Checking point in geofence', [
                'store_id' => $this->id,
                'point' => [$latitude, $longitude],
                'polygon' => $polygon
            ]);

            return $this->pointInPolygon([$latitude, $longitude], $polygon);
        } catch (\Exception $e) {
            \Log::error('Error checking geofence for store ' . $this->id . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if a point is inside a polygon using the ray casting algorithm.
     *
     * @param array $point [latitude, longitude]
     * @param array $polygon Array of [latitude, longitude] points
     * @return bool
     */
    private function pointInPolygon($point, $polygon)
    {
        $inside = false;
        for ($i = 0, $j = count($polygon) - 1; $i < count($polygon); $j = $i++) {
            $xi = $polygon[$i][0];
            $yi = $polygon[$i][1];
            $xj = $polygon[$j][0];
            $yj = $polygon[$j][1];
            
            \Log::debug('Checking polygon segment', [
                'point1' => [$xi, $yi],
                'point2' => [$xj, $yj],
                'test_point' => $point
            ]);
            
            $intersect = (($yi > $point[1]) !== ($yj > $point[1]))
                && ($point[0] < ($xj - $xi) * ($point[1] - $yi) / ($yj - $yi) + $xi);
            if ($intersect) {
                $inside = !$inside;
            }
        }
        
        \Log::debug('Point in polygon result', [
            'inside' => $inside,
            'store_id' => $this->id
        ]);
        
        return $inside;
    }
}
