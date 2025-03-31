<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StoreAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class StoreAddressController extends Controller
{
    /**
     * Display a listing of store addresses.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        try {
            $storeAddresses = StoreAddress::all();
            
            return response()->json([
                'status' => 'success',
                'data' => $storeAddresses
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching store addresses: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch store addresses: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created store address.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'address_line1' => 'required|string|max:255',
                'address_line2' => 'nullable|string|max:255',
                'formatted_address' => 'nullable|string',
                'city' => 'nullable|string|max:255',
                'state' => 'nullable|string|max:255',
                'postal_code' => 'nullable|string|max:20',
                'country' => 'nullable|string|max:255',
                'phone' => 'nullable|string|max:20',
                'email' => 'nullable|email|max:255',
                'latitude' => 'nullable|numeric',
                'longitude' => 'nullable|numeric',
                'is_pickup_location' => 'boolean',
                'is_delivery_location' => 'boolean',
                'is_active' => 'boolean',
                'opening_hours' => 'nullable|string',
                'notes' => 'nullable|string',
                'delivery_base_fee' => 'nullable|numeric',
                'delivery_fee_per_km' => 'nullable|numeric',
                'free_delivery_threshold' => 'nullable|numeric',
                'minimum_order_value' => 'nullable|numeric',
                'offers_free_delivery' => 'boolean',
                'delivery_radius_km' => 'nullable|integer',
                'geofence_coordinates' => 'nullable|array'
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            $storeAddress = StoreAddress::create($request->all());
            
            return response()->json([
                'status' => 'success',
                'message' => 'Store address created successfully',
                'data' => $storeAddress
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error creating store address: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create store address: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified store address.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        try {
            $storeAddress = StoreAddress::findOrFail($id);
            
            return response()->json([
                'status' => 'success',
                'data' => $storeAddress
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching store address: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch store address: ' . $e->getMessage()
            ], 404);
        }
    }

    /**
     * Update the specified store address.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|required|string|max:255',
                'address_line1' => 'sometimes|required|string|max:255',
                'address_line2' => 'nullable|string|max:255',
                'formatted_address' => 'nullable|string',
                'city' => 'nullable|string|max:255',
                'state' => 'nullable|string|max:255',
                'postal_code' => 'nullable|string|max:20',
                'country' => 'nullable|string|max:255',
                'phone' => 'nullable|string|max:20',
                'email' => 'nullable|email|max:255',
                'latitude' => 'nullable|numeric',
                'longitude' => 'nullable|numeric',
                'is_pickup_location' => 'boolean',
                'is_delivery_location' => 'boolean',
                'is_active' => 'boolean',
                'opening_hours' => 'nullable|string',
                'notes' => 'nullable|string',
                'delivery_base_fee' => 'nullable|numeric',
                'delivery_fee_per_km' => 'nullable|numeric',
                'free_delivery_threshold' => 'nullable|numeric',
                'minimum_order_value' => 'nullable|numeric',
                'offers_free_delivery' => 'boolean',
                'delivery_radius_km' => 'nullable|integer',
                'geofence_coordinates' => 'nullable|array'
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            $storeAddress = StoreAddress::findOrFail($id);
            $storeAddress->update($request->all());
            
            return response()->json([
                'status' => 'success',
                'message' => 'Store address updated successfully',
                'data' => $storeAddress
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating store address: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update store address: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified store address.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $storeAddress = StoreAddress::findOrFail($id);
            $storeAddress->delete();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Store address deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting store address: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete store address: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all pickup locations.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPickupLocations()
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
            Log::error('Error fetching pickup locations: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch pickup locations: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Find nearest pickup locations to a given latitude and longitude for admin access.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function findNearestLocations(Request $request)
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
            
            \Log::info('Finding nearest locations (admin)', [
                'latitude' => $latitude,
                'longitude' => $longitude
            ]);

            // Get all locations regardless of status for admin
            $locations = StoreAddress::all();
            
            // Filter by geofence and add service availability
            $locationsWithDistance = $locations
                ->map(function($location) use ($latitude, $longitude) {
                    $distance = $location->distanceFrom($latitude, $longitude);
                    $inGeofence = $location->isPointInGeofence($latitude, $longitude);
                    return [
                        'location' => $location,
                        'distance' => $distance,
                        'inGeofence' => $inGeofence,
                        'isPickupAvailable' => $location->is_pickup_location && $location->is_active,
                        'isDeliveryAvailable' => $location->is_delivery_location && $location->is_active
                    ];
                })
                ->filter(function($item) {
                    return $item['distance'] !== null;
                })
                ->sortBy('distance')
                ->take($limit)
                ->values();
            
            \Log::info('Found locations (admin)', [
                'count' => count($locationsWithDistance),
                'locations' => $locationsWithDistance->map(function($item) {
                    return [
                        'id' => $item['location']->id,
                        'pickup' => $item['isPickupAvailable'],
                        'delivery' => $item['isDeliveryAvailable'],
                        'active' => $item['location']->is_active
                    ];
                })
            ]);

            return response()->json([
                'status' => 'success',
                'data' => $locationsWithDistance
            ]);
        } catch (\Exception $e) {
            Log::error('Error finding nearest locations (admin): ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to find nearest locations'
            ], 500);
        }
    }
}
