<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeliverySettings;
use App\Models\StoreAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class DeliverySettingsController extends Controller
{
    /**
     * Get global delivery settings
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getGlobalSettings()
    {
        try {
            $settings = DeliverySettings::where('is_global', true)->first();
            
            if (!$settings) {
                // Create default settings if none exist
                $settings = DeliverySettings::create([
                    'is_global' => true,
                    'base_fee' => 500, // ₦500
                    'fee_per_km' => 100, // ₦100 per km
                    'free_threshold' => 10000, // ₦10,000
                    'min_order' => 0, // No minimum
                    'max_distance' => 20, // 20km
                    'is_active' => true
                ]);
            }
            
            return response()->json([
                'status' => 'success',
                'data' => $settings
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching global delivery settings: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch delivery settings'
            ], 500);
        }
    }
    
    /**
     * Update global delivery settings
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateGlobalSettings(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'base_fee' => 'required|numeric|min:0',
            'fee_per_km' => 'required|numeric|min:0',
            'free_threshold' => 'required|numeric|min:0',
            'min_order' => 'required|numeric|min:0',
            'max_distance' => 'required|numeric|min:1|max:100',
            'is_active' => 'required|boolean'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid input data',
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $settings = DeliverySettings::where('is_global', true)->first();
            
            if (!$settings) {
                $settings = new DeliverySettings();
                $settings->is_global = true;
            }
            
            $settings->base_fee = $request->base_fee;
            $settings->fee_per_km = $request->fee_per_km;
            $settings->free_threshold = $request->free_threshold;
            $settings->min_order = $request->min_order;
            $settings->max_distance = $request->max_distance;
            $settings->is_active = $request->is_active;
            $settings->save();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Delivery settings updated successfully',
                'data' => $settings
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating global delivery settings: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update delivery settings'
            ], 500);
        }
    }
    
    /**
     * Get store-specific delivery settings
     *
     * @param int $storeId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getStoreSettings($storeId)
    {
        try {
            $store = StoreAddress::find($storeId);
            
            if (!$store) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Store not found'
                ], 404);
            }
            
            // Get delivery settings from the store
            $settings = [
                'delivery_enabled' => $store->delivery_enabled ?? false,
                'delivery_base_fee' => $store->delivery_base_fee ?? 500,
                'delivery_fee_per_km' => $store->delivery_fee_per_km ?? 100,
                'delivery_free_threshold' => $store->delivery_free_threshold ?? 10000,
                'delivery_min_order' => $store->delivery_min_order ?? 0,
                'delivery_radius_km' => $store->delivery_radius_km ?? 20
            ];
            
            return response()->json([
                'status' => 'success',
                'data' => $settings
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching store delivery settings: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch store delivery settings'
            ], 500);
        }
    }
    
    /**
     * Update store-specific delivery settings
     *
     * @param Request $request
     * @param int $storeId
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateStoreSettings(Request $request, $storeId)
    {
        $validator = Validator::make($request->all(), [
            'delivery_enabled' => 'required|boolean',
            'delivery_base_fee' => 'required|numeric|min:0',
            'delivery_fee_per_km' => 'required|numeric|min:0',
            'delivery_free_threshold' => 'required|numeric|min:0',
            'delivery_min_order' => 'required|numeric|min:0',
            'delivery_radius_km' => 'required|numeric|min:1|max:100'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid input data',
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $store = StoreAddress::find($storeId);
            
            if (!$store) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Store not found'
                ], 404);
            }
            
            // Update store delivery settings
            $store->delivery_enabled = $request->delivery_enabled;
            $store->delivery_base_fee = $request->delivery_base_fee;
            $store->delivery_fee_per_km = $request->delivery_fee_per_km;
            $store->delivery_free_threshold = $request->delivery_free_threshold;
            $store->delivery_min_order = $request->delivery_min_order;
            $store->delivery_radius_km = $request->delivery_radius_km;
            $store->save();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Store delivery settings updated successfully',
                'data' => $store
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating store delivery settings: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update store delivery settings'
            ], 500);
        }
    }
}
