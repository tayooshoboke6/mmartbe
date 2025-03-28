<?php

namespace App\Services;

use App\Models\StoreAddress;
use App\Models\Order;
use Illuminate\Support\Facades\Log;

class DeliveryFeeService
{
    /**
     * Calculate delivery fee based on distance, order subtotal, and store settings
     *
     * @param float $subtotal Order subtotal
     * @param array $customerLocation Customer's location [latitude, longitude]
     * @param int|null $storeId Store ID (optional)
     * @return array Delivery fee details
     */
    public function calculateDeliveryFee(float $subtotal, array $customerLocation, ?int $storeId = null): array
    {
        try {
            Log::info('DeliveryFeeService: Starting calculation', [
                'subtotal' => $subtotal,
                'customerLocation' => $customerLocation,
                'storeId' => $storeId
            ]);
            
            // Validate customer location
            if (!isset($customerLocation[0]) || !isset($customerLocation[1]) || 
                !is_numeric($customerLocation[0]) || !is_numeric($customerLocation[1])) {
                Log::error('DeliveryFeeService: Invalid customer location', [
                    'customerLocation' => $customerLocation
                ]);
                return $this->getErrorResponse("Invalid customer location");
            }
            
            // Get the appropriate store
            $store = $this->getStore($storeId);
            
            if (!$store) {
                Log::error('DeliveryFeeService: No valid store found for delivery');
                return $this->getErrorResponse("No valid store found for delivery");
            }
            
            Log::info('DeliveryFeeService: Found store', [
                'store_id' => $store->id,
                'store_name' => $store->name,
                'store_location' => [$store->latitude, $store->longitude],
                'has_geofence' => !empty($store->geofence_coordinates)
            ]);
            
            // Validate store location
            if (!is_numeric($store->latitude) || !is_numeric($store->longitude)) {
                Log::error('DeliveryFeeService: Invalid store coordinates', [
                    'store_latitude' => $store->latitude,
                    'store_longitude' => $store->longitude
                ]);
                return $this->getErrorResponse("Store location is invalid");
            }
            
            // Calculate distance between customer and store
            $storeLocation = [$store->latitude, $store->longitude];
            $distanceInKm = $this->calculateDistance($customerLocation, $storeLocation);
            
            Log::info('DeliveryFeeService: Distance calculation', [
                'customerLocation' => $customerLocation,
                'storeLocation' => $storeLocation,
                'distanceInKm' => $distanceInKm
            ]);
            
            // Check if customer is within geofence (if available)
            $isWithinDeliveryZone = true;
            if (!empty($store->geofence_coordinates)) {
                $isWithinDeliveryZone = $this->isPointInPolygon(
                    $customerLocation[0], 
                    $customerLocation[1], 
                    json_decode($store->geofence_coordinates, true)
                );
                
                Log::info('DeliveryFeeService: Geofence check', [
                    'isWithinGeofence' => $isWithinDeliveryZone,
                    'customerLocation' => $customerLocation,
                    'geofence' => json_decode($store->geofence_coordinates, true)
                ]);
                
                if (!$isWithinDeliveryZone) {
                    Log::warning('DeliveryFeeService: Customer outside geofence', [
                        'customerLocation' => $customerLocation,
                        'distance' => $distanceInKm
                    ]);
                    return $this->getErrorResponse("Sorry, we don't currently deliver to your location.", $distanceInKm);
                }
            }
            
            // Get global settings
            $globalSettings = $this->getGlobalSettings();
            
            // Get store delivery settings
            $baseFee = $store->delivery_base_fee ?? $globalSettings['base_fee'] ?? 0; // No default fee
            $feePerKm = $store->delivery_fee_per_km ?? $globalSettings['fee_per_km'] ?? 100; // Default: ₦100 per km
            $freeThreshold = $store->free_delivery_threshold ?? $globalSettings['free_threshold'] ?? 10000; // Default: Free for orders over ₦10,000
            $minOrder = $store->minimum_order_value ?? $globalSettings['min_order'] ?? 0; // Default: No minimum order
            
            // Debug the actual values from the store
            Log::info('DeliveryFeeService: DEBUG STORE VALUES', [
                'store_id' => $store->id,
                'delivery_base_fee' => $store->delivery_base_fee,
                'delivery_fee_per_km' => $store->delivery_fee_per_km,
                'free_delivery_threshold' => $store->free_delivery_threshold,
                'minimum_order_value' => $store->minimum_order_value,
                'subtotal' => $subtotal,
                'is_free' => $subtotal >= $freeThreshold
            ]);
            
            Log::info('DeliveryFeeService: Delivery settings', [
                'baseFee' => $baseFee,
                'feePerKm' => $feePerKm,
                'freeThreshold' => $freeThreshold,
                'minOrder' => $minOrder,
                'subtotal' => $subtotal
            ]);
            
            // Check if order meets minimum requirement
            if ($subtotal < $minOrder) {
                Log::warning('DeliveryFeeService: Order below minimum', [
                    'subtotal' => $subtotal,
                    'minOrder' => $minOrder
                ]);
                return $this->getErrorResponse(
                    "Minimum order for delivery is ₦" . number_format($minOrder) . ". Please add more items.",
                    $distanceInKm
                );
            }
            
            // Calculate fee
            $fee = $baseFee;
            
            // Distance fee (fee per km after first 2km)
            $distanceFee = $distanceInKm <= 2 ? 0 : ceil($distanceInKm - 2) * $feePerKm;
            $fee += $distanceFee;
            
            // Free delivery for orders over threshold
            if ($subtotal >= $freeThreshold) {
                $fee = 0;
            }
            
            // Estimate delivery time (5 minutes + 3 minutes per km)
            $estimatedTime = 5 + ceil($distanceInKm * 3);
            
            Log::info('DeliveryFeeService: Fee calculation results', [
                'baseFee' => $baseFee,
                'distanceFee' => $distanceFee,
                'totalFee' => $fee,
                'estimatedTime' => $estimatedTime,
                'isFreeDelivery' => $subtotal >= $freeThreshold
            ]);
            
            // Prepare message based on fee
            $message = $fee === 0
                ? "Free delivery for your order!"
                : "Delivery fee: ₦" . number_format($fee) . " (" . number_format($distanceInKm, 1) . " km)";
            
            $result = [
                'fee' => $fee,
                'distance' => $distanceInKm,
                'currency' => 'NGN',
                'estimatedTime' => $estimatedTime,
                'isDeliveryAvailable' => true,
                'message' => $message,
                'store_id' => $store->id
            ];
            
            Log::info('DeliveryFeeService: Returning result', $result);
            
            return $result;
        } catch (\Exception $e) {
            Log::error('Error calculating delivery fee: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'customer_location' => $customerLocation ?? null,
                'store_id' => $storeId ?? null,
                'subtotal' => $subtotal ?? null
            ]);
            return $this->getErrorResponse("Unable to calculate delivery fee: " . $e->getMessage());
        }
    }
    
    /**
     * Get the appropriate store for delivery
     *
     * @param int|null $storeId Store ID
     * @return StoreAddress|null
     */
    protected function getStore(?int $storeId): ?StoreAddress
    {
        if ($storeId) {
            return StoreAddress::find($storeId);
        }
        
        // If no store ID provided, get the first active store
        return StoreAddress::where('is_active', true)
            ->where('is_delivery_location', true)
            ->first();
    }
    
    /**
     * Get global delivery settings
     *
     * @return array
     */
    protected function getGlobalSettings(): array
    {
        try {
            Log::info('DeliveryFeeService: Using default global settings (delivery_settings table not found)');
            return [
                'base_fee' => 0,           // No default fee
                'fee_per_km' => 100,         // Default: ₦100 per km
                'free_threshold' => 10000,   // Default: Free for orders over ₦10,000
                'min_order' => 0,            // Default: No minimum order
                'max_distance' => 20         // Default: Maximum 20km delivery radius
            ];
        } catch (\Exception $e) {
            Log::error('Error getting global settings: ' . $e->getMessage());
            return [
                'base_fee' => 0,
                'fee_per_km' => 100,
                'free_threshold' => 10000,
                'min_order' => 0,
                'max_distance' => 20
            ];
        }
    }
    
    /**
     * Check if a point is within a polygon
     *
     * @param float $x Point's x-coordinate
     * @param float $y Point's y-coordinate
     * @param array $polygon Polygon's coordinates
     * @return bool
     */
    protected function isPointInPolygon(float $x, float $y, array $polygon): bool
    {
        $n = count($polygon);
        $inside = false;
        
        $p1x = $polygon[0][0];
        $p1y = $polygon[0][1];
        
        for ($i = 1; $i <= $n; $i++) {
            $p2x = $polygon[$i % $n][0];
            $p2y = $polygon[$i % $n][1];
            
            if ($y > min($p1y, $p2y)) {
                if ($y <= max($p1y, $p2y)) {
                    if ($x <= max($p1x, $p2x)) {
                        if ($p1y != $p2y) {
                            $xinters = ($y - $p1y) * ($p2x - $p1x) / ($p2y - $p1y) + $p1x;
                        }
                        
                        if ($p1x == $p2x || $x <= $xinters) {
                            $inside = !$inside;
                        }
                    }
                }
            }
            
            $p1x = $p2x;
            $p1y = $p2y;
        }
        
        return $inside;
    }
    
    /**
     * Calculate distance between two points using Haversine formula
     *
     * @param array $point1 [latitude, longitude]
     * @param array $point2 [latitude, longitude]
     * @return float Distance in kilometers
     */
    public function calculateDistance(array $point1, array $point2): float
    {
        $lat1 = $point1[0];
        $lon1 = $point1[1];
        $lat2 = $point2[0];
        $lon2 = $point2[1];
        
        Log::info('DeliveryFeeService: Distance calculation inputs', [
            'point1' => $point1,
            'point2' => $point2,
            'lat1' => $lat1,
            'lon1' => $lon1,
            'lat2' => $lat2,
            'lon2' => $lon2
        ]);
        
        // Validate coordinates
        if (!is_numeric($lat1) || !is_numeric($lon1) || !is_numeric($lat2) || !is_numeric($lon2)) {
            Log::error('DeliveryFeeService: Invalid coordinates for distance calculation', [
                'lat1' => $lat1,
                'lon1' => $lon1,
                'lat2' => $lat2,
                'lon2' => $lon2
            ]);
            return 0;
        }
        
        $earthRadius = 6371; // Earth's radius in kilometers
        
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        
        $a = sin($dLat/2) * sin($dLat/2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon/2) * sin($dLon/2);
             
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        $distance = $earthRadius * $c;
        
        Log::info('DeliveryFeeService: Distance calculation result', [
            'distance_km' => $distance,
            'calculation_details' => [
                'dLat' => $dLat,
                'dLon' => $dLon,
                'a' => $a,
                'c' => $c
            ]
        ]);
        
        return $distance;
    }
    
    /**
     * Get error response format
     *
     * @param string $message Error message
     * @param float $distance Distance in km (optional)
     * @return array
     */
    protected function getErrorResponse(string $message, float $distance = 0): array
    {
        return [
            'fee' => 0,
            'distance' => $distance,
            'currency' => 'NGN',
            'estimatedTime' => 0,
            'isDeliveryAvailable' => false,
            'message' => $message
        ];
    }
}
