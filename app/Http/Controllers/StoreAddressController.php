<?php

namespace App\Http\Controllers;

use App\Models\StoreAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class StoreAddressController extends Controller
{
    /**
     * Get all active pickup locations for public access.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPublicPickupLocations()
    {
        try {
            $pickupLocations = StoreAddress::where('is_pickup_location', true)
                                          ->where('is_active', true)
                                          ->get();
            
            return response()->json([
                'status' => 'success',
                'data' => $pickupLocations
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching public pickup locations: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch pickup locations'
            ], 500);
        }
    }

    /**
     * Check if a point is within this store's geofence.
     *
     * @param float $latitude
     * @param float $longitude
     * @return bool
     */
    public function findNearestPickupLocations(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'latitude' => 'required|numeric',
                'longitude' => 'required|numeric',
                'limit' => 'nullable|integer|min:1|max:50'
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            $latitude = $request->input('latitude');
            $longitude = $request->input('longitude');
            $limit = $request->input('limit', 10); // Default 10 locations
            
            \Log::info('Finding nearest locations', [
                'latitude' => $latitude,
                'longitude' => $longitude
            ]);

            // Get all active locations that offer either pickup or delivery
            $locations = StoreAddress::where('is_active', true)
                ->where(function($query) {
                    $query->where('is_pickup_location', true)
                          ->orWhere('is_delivery_location', true);
                })
                ->get();
            
            // Filter by geofence and add service availability
            $locationsWithDistance = $locations
                ->filter(function($location) use ($latitude, $longitude) {
                    $inGeofence = $location->isPointInGeofence($latitude, $longitude);
                    \Log::debug('Geofence check result', [
                        'store_id' => $location->id,
                        'in_geofence' => $inGeofence
                    ]);
                    return $inGeofence;
                })
                ->map(function($location) use ($latitude, $longitude) {
                    $distance = $location->distanceFrom($latitude, $longitude);
                    return [
                        'location' => $location,
                        'distance' => $distance,
                        'isPickupAvailable' => $location->is_pickup_location,
                        'isDeliveryAvailable' => $location->is_delivery_location
                    ];
                })
                ->filter(function($item) {
                    return $item['distance'] !== null;
                })
                ->sortBy('distance')
                ->take($limit)
                ->values();
            
            \Log::info('Found locations', [
                'count' => count($locationsWithDistance),
                'locations' => $locationsWithDistance->map(function($item) {
                    return [
                        'id' => $item['location']->id,
                        'pickup' => $item['isPickupAvailable'],
                        'delivery' => $item['isDeliveryAvailable']
                    ];
                })
            ]);

            return response()->json([
                'status' => 'success',
                'data' => $locationsWithDistance
            ]);
        } catch (\Exception $e) {
            Log::error('Error finding nearest locations: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to find nearest locations'
            ], 500);
        }
    }
}
