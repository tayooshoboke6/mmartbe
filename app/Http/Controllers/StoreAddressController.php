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
     * Find nearest pickup locations to a given latitude and longitude for public access.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
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
            
            \Log::info('Finding nearest pickup locations', [
                'latitude' => $latitude,
                'longitude' => $longitude
            ]);

            // Get all active pickup locations
            $pickupLocations = StoreAddress::where('is_pickup_location', true)
                                          ->where('is_active', true)
                                          ->get();
            
            // Filter by geofence and calculate distances
            $locationsWithDistance = $pickupLocations
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
                        'distance' => $distance
                    ];
                })
                ->filter(function($item) {
                    return $item['distance'] !== null;
                })
                ->sortBy('distance')
                ->take($limit)
                ->values();
            
            \Log::info('Found delivery locations', [
                'count' => count($locationsWithDistance),
                'locations' => $locationsWithDistance->pluck('location.id')
            ]);

            return response()->json([
                'status' => 'success',
                'data' => $locationsWithDistance
            ]);
        } catch (\Exception $e) {
            \Log::error('Error finding nearest pickup locations: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to find nearest pickup locations'
            ], 500);
        }
    }
}
