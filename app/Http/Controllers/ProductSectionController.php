<?php

namespace App\Http\Controllers;

use App\Models\ProductSection;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProductSectionController extends Controller
{
    /**
     * Display a listing of the product sections for customers.
     * Only returns active sections with their associated products.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $query = ProductSection::where('is_active', true)
            ->orderBy('display_order', 'asc');
            
        // Filter by type if provided
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }
        
        $productSections = $query->get();
        
        // Load products for each section
        foreach ($productSections as $section) {
            $productQuery = Product::whereIn('id', $section->product_ids ?? [])
                ->where('is_active', true);
                
            // Apply limit if provided
            if ($request->has('limit')) {
                $productQuery->limit($request->limit);
            }
            
            $section->products = $productQuery->get();
        }
        
        return response()->json([
            'productSections' => $productSections
        ]);
    }
    
    /**
     * Get products for a specific section type.
     * This is useful for displaying products in a specific category or section.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function getProductsByType(Request $request)
    {
        // Log the request for debugging
        Log::info('Products by type request', ['request' => $request->all()]);
        
        // Validate request with more lenient validation
        $request->validate([
            'type' => 'required|string',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);
        
        $limit = $request->limit ?? 10;
        $type = $request->type;
        
        Log::info('Processing products by type', ['type' => $type, 'limit' => $limit]);
        
        return $this->getProductsByTypeInternal($type, $limit);
    }
    
    /**
     * Get products for a specific section type using URL parameter.
     * This is an alternative endpoint that uses a URL parameter instead of a query parameter.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $type
     * @return \Illuminate\Http\Response
     */
    public function getProductsByTypeParam(Request $request, $type)
    {
        // Log the request for debugging
        Log::info('Products by type param request', ['type' => $type, 'request' => $request->all()]);
        
        // Validate request
        $request->validate([
            'limit' => 'nullable|integer|min:1|max:50',
        ]);
        
        $limit = $request->limit ?? 10;
        
        Log::info('Processing products by type param', ['type' => $type, 'limit' => $limit]);
        
        return $this->getProductsByTypeInternal($type, $limit);
    }
    
    /**
     * Internal method to get products by type.
     * This is used by both getProductsByType and getProductsByTypeParam.
     *
     * @param  string  $type
     * @param  int  $limit
     * @return \Illuminate\Http\Response
     */
    private function getProductsByTypeInternal($type, $limit)
    {
        // Get products based on type
        $query = Product::where('is_active', true);
        
        switch ($type) {
            case 'featured':
                $query->where('is_featured', true);
                break;
            case 'new_arrivals':
                $query->where('is_new_arrival', true);
                break;
            case 'expiring_soon':
                $query->whereNotNull('expiry_date')
                    ->whereDate('expiry_date', '>', now())
                    ->whereDate('expiry_date', '<=', now()->addDays(30))
                    ->orderBy('expiry_date', 'asc');
                break;
            case 'best_sellers':
                $query->where('total_sold', '>', 0)
                    ->orderBy('total_sold', 'desc');
                break;
            case 'hot_deals':
                $query->whereNotNull('sale_price')
                    ->whereRaw('sale_price < base_price')
                    ->orderByRaw('(base_price - sale_price) / base_price DESC');
                break;
            default:
                // For custom or other types, use product sections
                $sections = ProductSection::where('type', $type)
                    ->where('is_active', true)
                    ->get();
                    
                $productIds = [];
                foreach ($sections as $section) {
                    $productIds = array_merge($productIds, $section->product_ids ?? []);
                }
                
                if (!empty($productIds)) {
                    $query->whereIn('id', $productIds);
                }
                break;
        }
        
        $products = $query->limit($limit)->get();
        
        Log::info('Products by type response', ['count' => count($products)]);
        
        return response()->json([
            'success' => true,
            'data' => $products,
            'pagination' => null // These endpoints don't use pagination
        ]);
    }
}
