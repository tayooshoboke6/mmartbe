<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductSection;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class ProductSectionController extends Controller
{
    /**
     * Display a listing of the product sections.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $productSections = ProductSection::orderBy('display_order', 'asc')->get();
        
        // Add success flag to match frontend expectations
        return response()->json([
            'success' => true,
            'productSections' => $productSections
        ]);
    }

    /**
     * Store a newly created product section in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        // Validate request
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|string|in:featured,hot_deals,new_arrivals,expiring_soon,best_sellers,clearance,recommended,custom',
            'background_color' => 'nullable|string|max:20',
            'text_color' => 'nullable|string|max:20',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'exists:products,id',
            'display_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        // Create product section
        $productSection = ProductSection::create($request->all());

        return response()->json([
            'message' => 'Product section created successfully',
            'productSection' => $productSection
        ], 201);
    }

    /**
     * Display the specified product section.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $productSection = ProductSection::findOrFail($id);
        
        return response()->json([
            'productSection' => $productSection
        ]);
    }

    /**
     * Update the specified product section in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        // Find product section
        $productSection = ProductSection::findOrFail($id);
        
        // Validate request
        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'type' => 'nullable|string|in:featured,hot_deals,new_arrivals,expiring_soon,best_sellers,clearance,recommended,custom',
            'background_color' => 'nullable|string|max:20',
            'text_color' => 'nullable|string|max:20',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'exists:products,id',
            'display_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        // Update product section
        $productSection->update($request->all());

        return response()->json([
            'message' => 'Product section updated successfully',
            'productSection' => $productSection
        ]);
    }

    /**
     * Remove the specified product section from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $productSection = ProductSection::findOrFail($id);
        $productSection->delete();
        
        return response()->json([
            'message' => 'Product section deleted successfully'
        ]);
    }

    /**
     * Toggle the status of the specified product section.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function toggle($id)
    {
        $productSection = ProductSection::findOrFail($id);
        $productSection->is_active = !$productSection->is_active;
        $productSection->save();
        
        return response()->json([
            'message' => 'Product section status toggled successfully',
            'productSection' => $productSection
        ]);
    }

    /**
     * Reorder product sections.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function reorder(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'section_ids' => 'required|array',
            'section_ids.*' => 'exists:product_sections,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        // Update display order for each section
        DB::transaction(function () use ($request) {
            foreach ($request->section_ids as $index => $id) {
                ProductSection::where('id', $id)->update(['display_order' => $index]);
            }
        });

        $productSections = ProductSection::orderBy('display_order', 'asc')->get();
        
        return response()->json([
            'message' => 'Product sections reordered successfully',
            'productSections' => $productSections
        ]);
    }
}
