<?php

namespace App\Http\Controllers;

use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;

class LocationController extends Controller
{
    /**
     * Display a listing of the locations.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $query = Location::query();
        
        // Default to active locations for non-admin users
        if (!$request->has('include_inactive') || !$request->user() || !$request->user()->hasRole('admin')) {
            $query->where('is_active', true);
        }
        
        $locations = $query->orderBy('name')->get();
        
        return response()->json($locations);
    }

    /**
     * Find nearby locations based on user coordinates.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function nearby(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'radius' => 'nullable|numeric|min:1|max:50',
            'limit' => 'nullable|integer|min:1|max:50',
            'open_now' => 'nullable|in:true,false,1,0',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        // Get search radius from cache or use default (6km)
        $radius = $request->radius ?? Cache::get('location_search_radius', 6.0);
        $limit = $request->limit ?? 10;
        
        $query = Location::active()
            ->nearby($request->latitude, $request->longitude, $radius);
        
        // Filter by open status if requested
        if ($request->has('open_now') && in_array($request->open_now, ['true', '1'], true)) {
            $query->where(function($q) {
                $dayOfWeek = strtolower(date('l')); // e.g., 'monday', 'tuesday', etc.
                $currentTime = date('H:i');
                
                // Check for locations with opening hours data
                $q->whereNotNull('opening_hours')
                  ->where('opening_hours', '!=', '[]')
                  ->where(function($timeQ) use ($dayOfWeek, $currentTime) {
                      // Check for 24-hour locations
                      $timeQ->whereRaw("JSON_CONTAINS(JSON_EXTRACT(opening_hours, '$.\"{$dayOfWeek}\"'), '\"24_hours\"')")
                            ->orWhereRaw("JSON_CONTAINS(JSON_EXTRACT(opening_hours, '$.\"{$dayOfWeek}\"'), '\"24hours\"')");
                      
                      // For time ranges, we'll rely on the isOpenNow method after fetching
                      // This is a simpler approach that avoids complex SQL
                  });
            });
        }
        
        $locations = $query->limit($limit)->get();
        
        // Calculate and add distance to each location
        foreach ($locations as $location) {
            $location->distance = $location->distanceFrom($request->latitude, $request->longitude);
            
            // Add open status
            $location->is_open = $location->isOpenNow();
        }
        
        // If open_now is requested, filter in PHP after fetching
        if ($request->has('open_now') && in_array($request->open_now, ['true', '1'], true)) {
            $locations = $locations->filter(function($location) {
                return $location->is_open === true;
            })->values();
        }
        
        // Sort by distance
        $locations = $locations->sortBy('distance')->values();
        
        return response()->json([
            'locations' => $locations,
            'search_radius' => $radius,
            'total_found' => $locations->count(),
        ]);
    }

    /**
     * Display the specified location.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $location = Location::findOrFail($id);
        
        return response()->json($location);
    }

    /**
     * Store a newly created location in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'address' => 'required|string|max:255',
            'city' => 'required|string|max:100',
            'state' => 'required|string|max:100',
            'zip_code' => 'required|string|max:20',
            'phone' => 'required|string|max:20',
            'email' => 'nullable|email|max:255',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'is_active' => 'boolean',
            'opening_hours' => 'nullable|array',
            // Pickup and delivery fields
            'is_pickup_available' => 'boolean',
            'is_delivery_available' => 'boolean',
            'delivery_radius_km' => 'nullable|numeric|min:0',
            'delivery_zone_polygon' => 'nullable|array',
            'delivery_base_fee' => 'nullable|numeric|min:0',
            'delivery_fee_per_km' => 'nullable|numeric|min:0',
            'delivery_free_threshold' => 'nullable|numeric|min:0',
            'delivery_min_order' => 'nullable|numeric|min:0',
            'max_delivery_distance_km' => 'nullable|numeric|min:0',
            'outside_geofence_fee' => 'nullable|numeric|min:0',
            'order_value_adjustments' => 'nullable|array',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        $location = Location::create($request->all());
        
        return response()->json([
            'message' => 'Location created successfully',
            'location' => $location,
        ], 201);
    }

    /**
     * Update the specified location in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $location = Location::findOrFail($id);
        
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'address' => 'sometimes|string|max:255',
            'city' => 'sometimes|string|max:100',
            'state' => 'sometimes|string|max:100',
            'zip_code' => 'sometimes|string|max:20',
            'phone' => 'sometimes|string|max:20',
            'email' => 'nullable|email|max:255',
            'latitude' => 'sometimes|numeric',
            'longitude' => 'sometimes|numeric',
            'is_active' => 'boolean',
            'opening_hours' => 'nullable|array',
            // Pickup and delivery fields
            'is_pickup_available' => 'boolean',
            'is_delivery_available' => 'boolean',
            'delivery_radius_km' => 'nullable|numeric|min:0',
            'delivery_zone_polygon' => 'nullable|array',
            'delivery_base_fee' => 'nullable|numeric|min:0',
            'delivery_fee_per_km' => 'nullable|numeric|min:0',
            'delivery_free_threshold' => 'nullable|numeric|min:0',
            'delivery_min_order' => 'nullable|numeric|min:0',
            'max_delivery_distance_km' => 'nullable|numeric|min:0',
            'outside_geofence_fee' => 'nullable|numeric|min:0',
            'order_value_adjustments' => 'nullable|array',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        $location->update($request->all());
        
        return response()->json([
            'message' => 'Location updated successfully',
            'location' => $location->fresh(),
        ]);
    }

    /**
     * Remove the specified location from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $location = Location::findOrFail($id);
        
        // Check if location has any pickup orders
        if ($location->pickupOrders()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete location with associated orders',
            ], 422);
        }
        
        $location->delete();
        
        return response()->json([
            'message' => 'Location deleted successfully',
        ]);
    }

    /**
     * Update the global search radius for nearby locations.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function updateRadius(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'radius' => 'required|numeric|min:1|max:50',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        // Store radius in cache
        Cache::put('location_search_radius', $request->radius, now()->addYears(1));
        
        return response()->json([
            'message' => 'Search radius updated successfully',
            'radius' => $request->radius,
        ]);
    }

    /**
     * Toggle the active status of a location.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function toggleStatus($id)
    {
        $location = Location::findOrFail($id);
        
        $location->is_active = !$location->is_active;
        $location->save();
        
        return response()->json([
            'message' => 'Location status updated successfully',
            'location' => $location->fresh(),
        ]);
    }

    /**
     * Toggle the pickup availability status of the specified location.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function togglePickup($id)
    {
        $location = Location::findOrFail($id);
        
        // Toggle the is_pickup_available status
        $location->is_pickup_available = !$location->is_pickup_available;
        $location->save();
        
        return response()->json([
            'message' => 'Pickup availability status toggled successfully',
            'location' => $location->fresh(),
        ]);
    }

    /**
     * Toggle the delivery availability status of the specified location.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function toggleDelivery($id)
    {
        $location = Location::findOrFail($id);
        
        // Toggle the is_delivery_available status
        $location->is_delivery_available = !$location->is_delivery_available;
        $location->save();
        
        return response()->json([
            'message' => 'Delivery availability status toggled successfully',
            'location' => $location->fresh(),
        ]);
    }
}
