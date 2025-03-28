<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;

class SettingsController extends Controller
{
    /**
     * Get all settings
     */
    public function index()
    {
        try {
            $settings = Setting::all()->pluck('value', 'key');
            
            return response()->json([
                'status' => 'success',
                'data' => $settings
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching settings: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch settings: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Update settings
     */
    public function update(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'settings' => 'required|array',
                'settings.*' => 'required'
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            $settings = $request->settings;
            
            foreach ($settings as $key => $value) {
                Setting::updateOrCreate(
                    ['key' => $key],
                    ['value' => $value]
                );
            }
            
            return response()->json([
                'status' => 'success',
                'message' => 'Settings updated successfully',
                'data' => Setting::all()->pluck('value', 'key')
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating settings: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update settings: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get a specific setting
     */
    public function show($key)
    {
        try {
            $setting = Setting::where('key', $key)->first();
            
            if (!$setting) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Setting not found'
                ], 404);
            }
            
            return response()->json([
                'status' => 'success',
                'data' => [
                    'key' => $setting->key,
                    'value' => $setting->value
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching setting: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch setting: ' . $e->getMessage()
            ], 500);
        }
    }
}
