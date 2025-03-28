<?php

namespace App\Http\Controllers;

use App\Models\Address;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class AddressController extends Controller
{
    /**
     * Get all addresses for a user
     *
     * @param  int  $userId
     * @return \Illuminate\Http\Response
     */
    public function index($userId)
    {
        $user = User::findOrFail($userId);
        
        // Check if the authenticated user is accessing their own addresses
        if (auth()->id() != $userId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to addresses'
            ], 403);
        }
        
        $addresses = $user->addresses()->get();
        
        return response()->json([
            'success' => true,
            'data' => $addresses
        ]);
    }
    
    /**
     * Get a specific address
     *
     * @param  int  $userId
     * @param  int  $addressId
     * @return \Illuminate\Http\Response
     */
    public function show($userId, $addressId)
    {
        // Check if the authenticated user is accessing their own address
        if (auth()->id() != $userId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to address'
            ], 403);
        }
        
        $address = Address::where('user_id', $userId)
                          ->where('id', $addressId)
                          ->firstOrFail();
        
        return response()->json([
            'success' => true,
            'data' => $address
        ]);
    }
    
    /**
     * Store a new address for a user
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $userId
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request, $userId)
    {
        // Check if the authenticated user is creating their own address
        if (auth()->id() != $userId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }
        
        // Check if user has reached the maximum number of addresses (2)
        $addressCount = Address::where('user_id', $userId)->count();
        if ($addressCount >= 2) {
            return response()->json([
                'success' => false,
                'message' => 'You can only have 2 addresses. Please delete an existing address before adding a new one.',
                'max_reached' => true
            ], 400);
        }
        
        // Validate input
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'phone' => 'required|string|max:20',
            'street' => 'required|string|max:255',
            'city' => 'required|string|max:100',
            'state' => 'required|string|max:100',
            'postalCode' => 'nullable|string|max:20',
            'country' => 'required|string|max:100',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }
        
        // If this is the first address or isDefault is true, make it the default
        $isDefault = $request->input('isDefault', false);
        
        // If this address is being set as default, unset any existing default
        if ($isDefault) {
            Address::where('user_id', $userId)
                  ->where('is_default', true)
                  ->update(['is_default' => false]);
        }
        
        // Create the address with proper field mapping
        $address = new Address([
            'user_id' => $userId,
            'name' => $request->name,
            'phone' => $request->phone,
            'street' => $request->street,
            'city' => $request->city,
            'state' => $request->state,
            'postal_code' => $request->postalCode,
            'country' => $request->country,
            'is_default' => $isDefault,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude
        ]);
        
        $address->save();
        
        return response()->json([
            'success' => true,
            'message' => 'Address created successfully',
            'data' => $address
        ], 201);
    }
    
    /**
     * Update an address
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $userId
     * @param  int  $addressId
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $userId, $addressId)
    {
        // Check if the authenticated user is updating their own address
        if (auth()->id() != $userId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to update address'
            ], 403);
        }
        
        $address = Address::where('user_id', $userId)
                          ->where('id', $addressId)
                          ->firstOrFail();
        
        $validator = Validator::make($request->all(), [
            'name' => 'string|max:255',
            'phone' => 'string|max:20',
            'street' => 'string|max:255',
            'city' => 'string|max:100',
            'state' => 'string|max:100',
            'postalCode' => 'nullable|string|max:20',
            'country' => 'string|max:100',
            'isDefault' => 'boolean',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }
        
        // If this address is being set as default, unset any existing default
        if ($request->has('isDefault') && $request->isDefault) {
            Address::where('user_id', $userId)
                  ->where('is_default', true)
                  ->where('id', '!=', $addressId)
                  ->update(['is_default' => false]);
        }
        
        // Update the address with proper field mapping
        if ($request->has('name')) $address->name = $request->name;
        if ($request->has('phone')) $address->phone = $request->phone;
        if ($request->has('street')) $address->street = $request->street;
        if ($request->has('city')) $address->city = $request->city;
        if ($request->has('state')) $address->state = $request->state;
        if ($request->has('postalCode')) $address->postal_code = $request->postalCode;
        if ($request->has('country')) $address->country = $request->country;
        if ($request->has('isDefault')) $address->is_default = $request->isDefault;
        if ($request->has('latitude')) $address->latitude = $request->latitude;
        if ($request->has('longitude')) $address->longitude = $request->longitude;
        
        $address->save();
        
        return response()->json([
            'success' => true,
            'message' => 'Address updated successfully',
            'data' => $address
        ]);
    }
    
    /**
     * Update address coordinates
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $userId
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateCoordinates(Request $request, $userId, $id)
    {
        // Validate request
        $validator = Validator::make($request->all(), [
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid coordinates',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Find the address
            $address = Address::where('user_id', $userId)
                ->where('id', $id)
                ->first();

            if (!$address) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Address not found'
                ], 404);
            }

            // Update coordinates
            $address->latitude = $request->latitude;
            $address->longitude = $request->longitude;
            $address->save();

            // Log the update
            Log::info('Address coordinates updated', [
                'address_id' => $id,
                'latitude' => $request->latitude,
                'longitude' => $request->longitude
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Address coordinates updated successfully',
                'data' => $address
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating address coordinates', [
                'address_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update address coordinates'
            ], 500);
        }
    }
    
    /**
     * Delete an address
     *
     * @param  int  $userId
     * @param  int  $addressId
     * @return \Illuminate\Http\Response
     */
    public function destroy($userId, $addressId)
    {
        // Check if the authenticated user is deleting their own address
        if (auth()->id() != $userId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to delete address'
            ], 403);
        }
        
        $address = Address::where('user_id', $userId)
                          ->where('id', $addressId)
                          ->firstOrFail();
        
        $address->delete();
        
        return response()->json([
            'success' => true,
            'message' => 'Address deleted successfully'
        ]);
    }
    
    /**
     * Set an address as default
     *
     * @param  int  $userId
     * @param  int  $addressId
     * @return \Illuminate\Http\Response
     */
    public function setDefault($userId, $addressId)
    {
        // Check if the authenticated user is updating their own address
        if (auth()->id() != $userId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to update address'
            ], 403);
        }
        
        $address = Address::where('user_id', $userId)
                          ->where('id', $addressId)
                          ->firstOrFail();
        
        // Unset any existing default address
        Address::where('user_id', $userId)
              ->where('is_default', true)
              ->update(['is_default' => false]);
        
        // Set this address as default
        $address->is_default = true;
        $address->save();
        
        return response()->json([
            'success' => true,
            'message' => 'Address set as default successfully',
            'data' => $address
        ]);
    }
}
