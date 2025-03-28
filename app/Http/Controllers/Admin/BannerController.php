<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Banner;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class BannerController extends Controller
{
    /**
     * Display a listing of the banners.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $banners = Banner::all();
        
        return response()->json([
            'status' => 'success',
            'banners' => $banners
        ]);
    }

    /**
     * Store a newly created banner in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'label' => 'required|string|max:255',
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'image' => 'nullable|string',
            'bgColor' => 'required|string',
            'imgBgColor' => 'required|string',
            'link' => 'nullable|string',
            'active' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Log the request data for debugging
        \Log::info('Banner create request data:', $request->all());

        $banner = Banner::create([
            'label' => $request->label,
            'title' => $request->title,
            'description' => $request->description,
            'image' => $request->image,
            'bg_color' => $request->bgColor, // Convert camelCase to snake_case
            'img_bg_color' => $request->imgBgColor, // Convert camelCase to snake_case
            'link' => $request->link,
            'active' => $request->active ?? true
        ]);

        // Transform the banner for response
        $transformedBanner = [
            'id' => $banner->id,
            'label' => $banner->label,
            'title' => $banner->title,
            'description' => $banner->description,
            'image' => $banner->image,
            'bgColor' => $banner->bg_color, // Convert snake_case to camelCase
            'imgBgColor' => $banner->img_bg_color, // Convert snake_case to camelCase
            'link' => $banner->link,
            'active' => (bool)$banner->active,
            'createdAt' => $banner->created_at,
            'updatedAt' => $banner->updated_at
        ];

        // Log the created banner for debugging
        \Log::info('Banner created:', $banner->toArray());

        return response()->json([
            'status' => 'success',
            'message' => 'Banner created successfully',
            'banner' => $transformedBanner
        ]);
    }

    /**
     * Display the specified banner.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        // This is a placeholder for when we implement the Banner model
        return response()->json([
            'success' => true,
            'data' => [
                'id' => $id,
                'title' => 'Banner ' . $id,
                'subtitle' => 'Banner subtitle',
                'image_url' => 'https://via.placeholder.com/1200x400',
                'link' => '/products/category-' . $id,
                'is_active' => true,
                'position' => $id,
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ]
        ]);
    }

    /**
     * Update the specified banner in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        // Log all incoming data for debugging
        \Log::info('Banner update request received:', [
            'id' => $id,
            'request_data' => $request->all(),
            'content_type' => $request->header('Content-Type'),
            'request_keys' => array_keys($request->all())
        ]);
        
        try {
            $banner = Banner::findOrFail($id);
            
            // Check if this is just a status toggle request
            if ($request->has('active') && $request->has('id')) {
                // This is likely a status toggle request
                $banner->active = $request->boolean('active');
                $banner->save();
                
                // Log the toggle action for debugging
                \Log::info('Banner status toggled via update endpoint:', [
                    'id' => $id, 
                    'new_status' => $banner->active,
                    'request_data' => $request->all()
                ]);
                
                // Transform the banner for response
                $transformedBanner = [
                    'id' => $banner->id,
                    'label' => $banner->label,
                    'title' => $banner->title,
                    'description' => $banner->description,
                    'image' => $banner->image,
                    'bgColor' => $banner->bg_color,
                    'imgBgColor' => $banner->img_bg_color,
                    'link' => $banner->link,
                    'active' => (bool)$banner->active,
                    'createdAt' => $banner->created_at,
                    'updatedAt' => $banner->updated_at
                ];
                
                return response()->json([
                    'status' => 'success',
                    'message' => 'Banner status updated successfully',
                    'banner' => $transformedBanner
                ]);
            }
            
            // If it's a full update request, proceed with validation
            $validator = Validator::make($request->all(), [
                'label' => 'required|string|max:255',
                'title' => 'required|string|max:255',
                'description' => 'required|string',
                'image' => 'nullable|string',
                'bgColor' => 'required|string',
                'imgBgColor' => 'required|string',
                'link' => 'nullable|string',
                'active' => 'boolean'
            ]);

            if ($validator->fails()) {
                \Log::warning('Banner update validation failed:', [
                    'id' => $id,
                    'errors' => $validator->errors()->toArray()
                ]);
                
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $banner->update([
                'label' => $request->label,
                'title' => $request->title,
                'description' => $request->description,
                'image' => $request->image,
                'bg_color' => $request->bgColor, // Convert camelCase to snake_case
                'img_bg_color' => $request->imgBgColor, // Convert camelCase to snake_case
                'link' => $request->link,
                'active' => $request->has('active') ? $request->active : $banner->active
            ]);

            // Transform the banner for response
            $transformedBanner = [
                'id' => $banner->id,
                'label' => $banner->label,
                'title' => $banner->title,
                'description' => $banner->description,
                'image' => $banner->image,
                'bgColor' => $banner->bg_color, // Convert snake_case to camelCase
                'imgBgColor' => $banner->img_bg_color, // Convert snake_case to camelCase
                'link' => $banner->link,
                'active' => (bool)$banner->active,
                'createdAt' => $banner->created_at,
                'updatedAt' => $banner->updated_at
            ];

            // Log the updated banner for debugging
            \Log::info('Banner updated:', $banner->toArray());

            return response()->json([
                'status' => 'success',
                'message' => 'Banner updated successfully',
                'banner' => $transformedBanner
            ]);
        } catch (\Exception $e) {
            \Log::error('Banner update failed:', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update banner',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified banner from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $banner = Banner::findOrFail($id);
            $banner->delete();
            
            // Log the deletion for debugging
            \Log::info('Banner deleted:', ['id' => $id]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Banner deleted successfully'
            ]);
        } catch (\Exception $e) {
            // Log the error for debugging
            \Log::error('Banner deletion failed:', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete banner',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the position of banners.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function reorder(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'banners' => 'required|array',
                'banners.*.id' => 'required|exists:banners,id',
                'banners.*.position' => 'required|integer|min:0'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Log the reorder request data for debugging
            \Log::info('Banner reorder request data:', $request->all());

            // Update the position of each banner
            foreach ($request->banners as $bannerData) {
                $banner = Banner::findOrFail($bannerData['id']);
                $banner->position = $bannerData['position'];
                $banner->save();
            }
            
            return response()->json([
                'status' => 'success',
                'message' => 'Banners reordered successfully'
            ]);
        } catch (\Exception $e) {
            // Log the error for debugging
            \Log::error('Banner reordering failed:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to reorder banners',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle the active status of a banner.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function toggleStatus($id)
    {
        try {
            $banner = Banner::findOrFail($id);
            $banner->active = !$banner->active;
            $banner->save();
            
            // Log the toggle action for debugging
            \Log::info('Banner status toggled via dedicated endpoint:', [
                'id' => $id, 
                'new_status' => $banner->active
            ]);
            
            // Transform the banner for response to match frontend expectations
            $transformedBanner = [
                'id' => $banner->id,
                'label' => $banner->label,
                'title' => $banner->title,
                'description' => $banner->description,
                'image' => $banner->image,
                'bgColor' => $banner->bg_color,
                'imgBgColor' => $banner->img_bg_color,
                'link' => $banner->link,
                'active' => (bool)$banner->active,
                'createdAt' => $banner->created_at,
                'updatedAt' => $banner->updated_at
            ];
            
            return response()->json([
                'status' => 'success',
                'message' => 'Banner status toggled successfully',
                'banner' => $transformedBanner
            ]);
        } catch (\Exception $e) {
            // Log the error for debugging
            \Log::error('Banner status toggle failed:', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to toggle banner status',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
