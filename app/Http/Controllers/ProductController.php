<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductMeasurement;
use App\Models\Category;
use App\Models\ProductImage;
use App\Models\Order;
use App\Models\User;
use App\Services\CloudinaryService;
use App\Services\NotificationService;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class ProductController extends Controller
{
    protected $cloudinaryService;
    protected $cachePrefix = 'products_';
    protected $cacheDuration = 900; // 15 minutes in seconds

    /**
     * Create a new controller instance.
     *
     * @param CloudinaryService $cloudinaryService
     * @return void
     */
    public function __construct(CloudinaryService $cloudinaryService)
    {
        $this->cloudinaryService = $cloudinaryService;
    }

    /**
     * Get a dynamic cache key based on the latest product update.
     *
     * @return string
     */
    protected function getCacheKey($suffix = '')
    {
        // Get the timestamp of the most recently updated product
        $latestUpdate = Product::max('updated_at');
        $timestamp = $latestUpdate ? strtotime($latestUpdate) : time();
        
        return $this->cachePrefix . 'timestamp_' . $timestamp . $suffix;
    }

    /**
     * Clear all product-related cache.
     *
     * @return void
     */
    protected function clearProductCache()
    {
        // Clear cache for all keys with the products_ prefix
        $keys = Cache::get('product_cache_keys', []);
        foreach ($keys as $key) {
            Cache::forget($key);
        }
        Cache::forget('product_cache_keys');
        
        // Also clear any category cache since product counts might be affected
        if (method_exists('\App\Http\Controllers\Admin\CategoryAdminController', 'clearCategoryCache')) {
            app()->make('\App\Http\Controllers\Admin\CategoryAdminController')->clearCategoryCache();
        }
    }

    /**
     * Add a key to the list of product cache keys.
     *
     * @param string $key
     * @return void
     */
    protected function addCacheKey($key)
    {
        $keys = Cache::get('product_cache_keys', []);
        if (!in_array($key, $keys)) {
            $keys[] = $key;
            Cache::put('product_cache_keys', $keys, $this->cacheDuration * 2);
        }
    }

    /**
     * Display a listing of the products.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        // Generate a unique cache key based on the request parameters
        $cacheKey = $this->getCacheKey('_index_' . md5(json_encode($request->all())));
        $this->addCacheKey($cacheKey);

        // Try to get from cache first
        return Cache::remember($cacheKey, $this->cacheDuration, function () use ($request) {
            $query = Product::with(['category', 'measurements'])
                ->when($request->has('category_id'), function ($q) use ($request) {
                    return $q->where('category_id', $request->category_id);
                })
                ->when($request->has('featured'), function ($q) use ($request) {
                    return $q->where('is_featured', $request->boolean('featured'));
                })
                ->when($request->has('search'), function ($q) use ($request) {
                    return $q->where('name', 'like', '%' . $request->search . '%')
                        ->orWhere('description', 'like', '%' . $request->search . '%');
                })
                ->when($request->has('min_price'), function ($q) use ($request) {
                    return $q->where('base_price', '>=', $request->min_price);
                })
                ->when($request->has('max_price'), function ($q) use ($request) {
                    return $q->where('base_price', '<=', $request->max_price);
                })
                // Filter by stock status
                ->when($request->has('stock_status'), function ($q) use ($request) {
                    switch ($request->stock_status) {
                        case 'in_stock':
                            return $q->where('stock_quantity', '>', 10); // Products with more than 10 items
                        case 'low_stock':
                            return $q->whereBetween('stock_quantity', [1, 10]); // Products with 1-10 items
                        case 'out_of_stock':
                            return $q->where('stock_quantity', '=', 0); // Products with 0 items
                        default:
                            return $q;
                    }
                })
                // Filter by expiry status
                ->when($request->has('expiry_status'), function ($q) use ($request) {
                    $today = now()->format('Y-m-d');
                    $thirtyDaysFromNow = now()->addDays(30)->format('Y-m-d');
                    
                    switch ($request->expiry_status) {
                        case 'about_to_expire':
                            // Products expiring within the next 30 days
                            return $q->whereNotNull('expiry_date')
                                    ->whereDate('expiry_date', '>=', $today)
                                    ->whereDate('expiry_date', '<=', $thirtyDaysFromNow);
                        case 'expired':
                            // Products that have already expired
                            return $q->whereNotNull('expiry_date')
                                    ->whereDate('expiry_date', '<', $today);
                        default:
                            return $q;
                    }
                });

            // Default to active products for non-admin users
            if (!$request->has('include_inactive') || !$request->user() || !$request->user()->hasRole('admin')) {
                $query->where('is_active', true);
            }

            $products = $query->orderBy($request->sort_by ?? 'created_at', $request->sort_direction ?? 'desc')
                ->paginate($request->per_page ?? 15);

            return response()->json([
                'success' => true,
                'data' => $products->items(),
                'pagination' => [
                    'current_page' => $products->currentPage(),
                    'last_page' => $products->lastPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total()
                ]
            ]);
        });
    }

    /**
     * Store a newly created product in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        try {
            // Log the incoming request data for debugging
            Log::info('Product creation request data:', [
                'request_data' => $request->all()
            ]);

            // Validate request
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'price' => 'required|numeric|min:0',
                'sale_price' => 'nullable|numeric|min:0',
                'stock' => 'required|integer|min:0',
                'sku' => 'required|string|max:100|unique:products',
                'category_id' => 'required|exists:categories,id',
                'image_url' => 'nullable|string',
                'images' => 'nullable|array',
                'images.*' => 'nullable|string',
                'measurements' => 'nullable|array',
                'measurements.*.name' => 'nullable|string|max:100',
                'measurements.*.value' => 'nullable|string|max:100',
                'measurements.*.unit' => 'nullable|string|max:20',
                'measurements.*.price_adjustment' => 'nullable|numeric',
                'measurements.*.stock' => 'nullable|integer|min:0',
                'measurements.*.is_default' => 'nullable|boolean',
                'weight' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                Log::error('Product validation failed:', [
                    'errors' => $validator->errors()->toArray()
                ]);
                return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
            }

            // Generate slug
            $slug = Str::slug($request->name);

            // Handle main product image
            $productImage = $request->image_url;
            // For now, we'll use the image URL directly without Cloudinary processing
            // This will be replaced with proper Cloudinary integration later
            
            // Add debugging logs
            Log::info('Product creation image URL handling:', [
                'request_has_image_url' => $request->has('image_url'),
                'request_image_url' => $request->image_url,
                'product_image_variable' => $productImage
            ]);
            
            // Create product
            $product = Product::create([
                'name' => $request->name,
                'slug' => $slug,
                'description' => $request->description,
                'short_description' => $request->short_description,
                'base_price' => $request->price, // Map price to base_price
                'sale_price' => $request->sale_price,
                'stock_quantity' => $request->stock, // Map stock to stock_quantity
                'sku' => $request->sku,
                'category_id' => $request->category_id,
                'is_active' => $request->boolean('is_active', true),
                'is_featured' => $request->boolean('is_featured', false),
                'is_new_arrival' => $request->boolean('is_new_arrival', false),
                'is_hot_deal' => $request->boolean('is_hot_deal', false),
                'is_best_seller' => $request->boolean('is_best_seller', false),
                'is_expiring_soon' => $request->boolean('is_expiring_soon', false),
                'is_clearance' => $request->boolean('is_clearance', false),
                'is_recommended' => $request->boolean('is_recommended', false),
                'brand' => $request->brand,
                'barcode' => $request->barcode,
                'expiry_date' => $request->expiry_date,
                'meta_data' => $request->meta_data,
                'image_url' => $request->image_url, // Use the raw image URL directly from the request
                'weight' => $request->weight,
            ]);

            // If image_url is provided, also create a related image record
            if ($request->image_url) {
                $product->images()->create([
                    'image_path' => $request->image_url,
                    'is_primary' => true,
                    'sort_order' => 0
                ]);
                
                // Refresh the product to ensure the relationship is loaded correctly
                $product = $product->fresh();
            }
            
            // Create measurements if provided
            if ($request->has('measurements')) {
                foreach ($request->measurements as $measurementData) {
                    $product->measurements()->create([
                        'name' => $measurementData['name'],
                        'value' => $measurementData['value'],
                        'unit' => $measurementData['unit'] ?? null,
                        'price' => $product->base_price + ($measurementData['price_adjustment'] ?? 0),
                        'sale_price' => $product->sale_price ? ($product->sale_price + ($measurementData['price_adjustment'] ?? 0)) : null,
                        'stock_quantity' => $measurementData['stock'] ?? $product->stock_quantity,
                        'sku' => $product->sku . '-' . strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $measurementData['name']), 0, 3)),
                        'is_default' => $measurementData['is_default'] ?? false,
                        'is_active' => true,
                    ]);
                }
            }

            // Create additional images if provided
            if ($request->has('images')) {
                foreach ($request->images as $imageUrl) {
                    // Handle image URL or base64
                    $finalImageUrl = $imageUrl;
                    if (strpos($imageUrl, 'data:image') === 0) {
                        // Handle base64 encoded image
                        $finalImageUrl = $this->uploadBase64Image($imageUrl, $request->sku . '-' . uniqid());
                    }
                    
                    $product->images()->create([
                        'image_path' => $finalImageUrl,
                        'is_primary' => false,
                        'sort_order' => 0
                    ]);
                }
            }

            // Clear cache after creating a new product
            $this->clearProductCache();

            return response()->json(['message' => 'Product created successfully', 'product' => $product->load('category', 'measurements', 'images')], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to create product: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified product.
     *
     * @param  string  $idOrSlug
     * @return \Illuminate\Http\Response
     */
    public function show($idOrSlug)
    {
        // Generate a cache key for this specific product
        $cacheKey = $this->getCacheKey('_show_' . $idOrSlug);
        $this->addCacheKey($cacheKey);

        // Try to get from cache first
        return Cache::remember($cacheKey, $this->cacheDuration, function () use ($idOrSlug) {
            // Try to find by ID first
            if (is_numeric($idOrSlug)) {
                $product = Product::with(['category', 'measurements', 'images'])->findOrFail($idOrSlug);
            } else {
                // If not numeric, try to find by slug
                $product = Product::with(['category', 'measurements', 'images'])
                    ->where('slug', $idOrSlug)
                    ->firstOrFail();
            }

            // Log the response for debugging
            Log::info('Product detail response for ID/Slug: ' . $idOrSlug, [
                'product' => $product->toArray()
            ]);

            // Format the response to match what the frontend expects
            return response()->json([
                'success' => true,
                'product' => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'description' => $product->description,
                    'short_description' => $product->short_description,
                    'base_price' => $product->base_price,
                    'price' => $product->base_price, // Alias for frontend compatibility
                    'sale_price' => $product->sale_price,
                    'stock_quantity' => $product->stock_quantity,
                    'stock' => $product->stock_quantity, // Alias for frontend compatibility
                    'sku' => $product->sku,
                    'barcode' => $product->barcode,
                    'is_featured' => $product->is_featured,
                    'featured' => $product->is_featured, // Alias for frontend compatibility
                    'is_active' => $product->is_active,
                    'is_new_arrival' => $product->is_new_arrival,
                    'is_hot_deal' => $product->is_hot_deal,
                    'is_best_seller' => $product->is_best_seller,
                    'is_expiring_soon' => $product->is_expiring_soon,
                    'is_clearance' => $product->is_clearance,
                    'is_recommended' => $product->is_recommended,
                    'category_id' => $product->category_id,
                    'category' => $product->category,
                    'category_name' => $product->category ? $product->category->name : null,
                    'brand' => $product->brand,
                    'expiry_date' => $product->expiry_date,
                    'meta_data' => $product->meta_data,
                    'total_sold' => $product->total_sold,
                    'created_at' => $product->created_at,
                    'updated_at' => $product->updated_at,
                    'images' => $product->images->map(function($image) {
                        return $image->image_path;
                    }),
                    'image' => $product->image,
                    'image_url' => $product->image_url,
                    'measurements' => $product->measurements,
                    'is_on_sale' => $product->is_on_sale,
                    'is_in_stock' => $product->is_in_stock,
                    'discount_percentage' => $product->discount_percentage
                ]
            ]);
        });
    }

    /**
     * Update the specified product in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        try {
            // Log the incoming request data for debugging
            Log::info('Product update request data:', [
                'request_data' => $request->all(),
                'has_image_url' => $request->has('image_url'),
                'image_url_value' => $request->image_url
            ]);
            
            // Find the product
            $product = Product::findOrFail($id);
            
            // Map frontend field names to backend field names if needed
            $requestData = $request->all();
            
            // Handle price field (frontend might send 'price' instead of 'base_price')
            if (isset($requestData['price']) && !isset($requestData['base_price'])) {
                $requestData['base_price'] = $requestData['price'];
            }
            
            // Handle stock field (frontend might send 'stock' instead of 'stock_quantity')
            if (isset($requestData['stock']) && !isset($requestData['stock_quantity'])) {
                $requestData['stock_quantity'] = $requestData['stock'];
            }
            
            // Validate request
            $validator = Validator::make($requestData, [
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'base_price' => 'required|numeric|min:0',
                'sale_price' => 'nullable|numeric|min:0',
                'stock_quantity' => 'required|integer|min:0',
                'sku' => 'required|string|max:100|unique:products,sku,' . $id,
                'category_id' => 'required|exists:categories,id',
                'image_url' => 'nullable|string',
                'images' => 'nullable|array',
                'images.*' => 'nullable|string',
                'measurements' => 'nullable|array',
                'measurements.*.name' => 'nullable|string|max:100',
                'measurements.*.value' => 'nullable|string|max:100',
                'measurements.*.unit' => 'nullable|string|max:20',
                'measurements.*.price_adjustment' => 'nullable|numeric',
                'measurements.*.stock' => 'nullable|integer|min:0',
                'measurements.*.is_default' => 'nullable|boolean',
                'weight' => 'nullable|string',
            ]);
            
            if ($validator->fails()) {
                Log::error('Product update validation failed:', [
                    'errors' => $validator->errors()->toArray()
                ]);
                return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
            }
            
            // Generate slug if name changed
            $slug = $product->slug;
            if ($request->name !== $product->name) {
                $slug = Str::slug($request->name);
            }
            
            // Handle product image
            $productImage = $product->image_url;
            $imageUpdated = false;
            
            Log::info('Image URL handling:', [
                'has_image_url' => $request->has('image_url'),
                'request_image_url' => $request->image_url,
                'product_image_url' => $product->image_url
            ]);
            
            // Always update the image_url if it's provided in the request
            if ($request->image_url !== null) {
                $productImage = $request->image_url;
                $imageUpdated = true;
                
                // If image_url is updated, also update or create a related primary image record
                if ($productImage) {
                    // Check if a primary image already exists
                    $primaryImage = $product->images()->where('is_primary', true)->first();
                    
                    if ($primaryImage) {
                        // Update existing primary image
                        $primaryImage->update([
                            'image_path' => $productImage
                        ]);
                    } else {
                        // Create new primary image
                        $product->images()->create([
                            'image_path' => $productImage,
                            'is_primary' => true,
                            'sort_order' => 0
                        ]);
                    }
                    
                    // Refresh the product to ensure the relationship is loaded correctly
                    $product = $product->fresh();
                }
            }
            
            // Update product
            $updateData = [
                'name' => $request->name,
                'slug' => $slug,
                'base_price' => $requestData['base_price'],
                'stock_quantity' => $requestData['stock_quantity'],
                'sku' => $request->sku,
                'category_id' => $request->category_id,
                'is_active' => $request->boolean('is_active', true),
                'is_featured' => $request->boolean('is_featured', false),
                'image_url' => $productImage
            ];

            // Only add fields if they are present in the request
            if ($request->has('description')) {
                $updateData['description'] = $request->description;
            }
            
            if ($request->has('short_description')) {
                $updateData['short_description'] = $request->short_description;
            }
            
            if ($request->has('sale_price')) {
                $updateData['sale_price'] = $request->sale_price;
            }
            
            if ($request->has('barcode')) {
                $updateData['barcode'] = $request->barcode;
            }
            
            if ($request->has('brand')) {
                $updateData['brand'] = $request->brand;
            }
            
            if ($request->has('expiry_date')) {
                $updateData['expiry_date'] = $request->expiry_date;
            }
            
            if ($request->has('meta_data')) {
                $updateData['meta_data'] = $request->meta_data;
            }
            
            if ($request->has('total_sold')) {
                $updateData['total_sold'] = $request->total_sold;
            }
            
            if ($request->has('weight')) {
                $updateData['weight'] = $request->weight;
            }
            
            // Boolean flags
            if ($request->has('is_new_arrival')) {
                $updateData['is_new_arrival'] = $request->boolean('is_new_arrival');
            }
            
            if ($request->has('is_hot_deal')) {
                $updateData['is_hot_deal'] = $request->boolean('is_hot_deal');
            }
            
            if ($request->has('is_best_seller')) {
                $updateData['is_best_seller'] = $request->boolean('is_best_seller');
            }
            
            if ($request->has('is_expiring_soon')) {
                $updateData['is_expiring_soon'] = $request->boolean('is_expiring_soon');
            }
            
            if ($request->has('is_clearance')) {
                $updateData['is_clearance'] = $request->boolean('is_clearance');
            }
            
            if ($request->has('is_recommended')) {
                $updateData['is_recommended'] = $request->boolean('is_recommended');
            }
            
            // Update the product with the filtered data
            $product->update($updateData);
            
            // Check if stock is low and send alert if needed
            $this->checkAndSendLowStockAlert($product);
            
            // Create measurements if provided
            if ($request->has('measurements')) {
                foreach ($request->measurements as $measurementData) {
                    $product->measurements()->create([
                        'name' => $measurementData['name'],
                        'value' => $measurementData['value'],
                        'unit' => $measurementData['unit'] ?? null,
                        'price' => $product->base_price + ($measurementData['price_adjustment'] ?? 0),
                        'sale_price' => $product->sale_price ? ($product->sale_price + ($measurementData['price_adjustment'] ?? 0)) : null,
                        'stock_quantity' => $measurementData['stock'] ?? $product->stock_quantity,
                        'sku' => $product->sku . '-' . strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $measurementData['name']), 0, 3)),
                        'is_default' => $measurementData['is_default'] ?? false,
                        'is_active' => true,
                    ]);
                }
            }

            // Create additional images if provided
            if ($request->has('images')) {
                foreach ($request->images as $imageUrl) {
                    // Handle image URL or base64
                    $finalImageUrl = $imageUrl;
                    if (strpos($imageUrl, 'data:image') === 0) {
                        // Handle base64 encoded image
                        $finalImageUrl = $this->uploadBase64Image($imageUrl, $request->sku . '-' . uniqid());
                    }
                    
                    $product->images()->create([
                        'image_path' => $finalImageUrl,
                        'is_primary' => false,
                        'sort_order' => 0
                    ]);
                }
            }

            // Clear cache after updating a product
            $this->clearProductCache();

            // Refresh the product model to get the latest data
            $product = Product::with('category', 'measurements', 'images')->find($product->id);

            return response()->json(['message' => 'Product updated successfully', 'product' => $product]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to update product: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified product from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $product = Product::findOrFail($id);

        DB::beginTransaction();

        try {
            // Delete related measurements
            $product->measurements()->delete();

            // Delete related images
            $product->images()->delete();

            // Delete the product
            $product->delete();

            DB::commit();

            // Clear cache after deleting a product
            $this->clearProductCache();

            return response()->json([
                'message' => 'Product deleted successfully',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to delete product: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Toggle the featured status of a product.
     *
     * @param  int  $id
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function toggleFeatured($id, Request $request)
    {
        try {
            $product = Product::findOrFail($id);
            
            // Update the featured status based on the request or toggle the current value
            $isFeatured = $request->has('is_featured') 
                ? $request->boolean('is_featured') 
                : !$product->is_featured;
            
            $product->is_featured = $isFeatured;
            $product->save();
            
            // Clear cache after updating a product
            $this->clearProductCache();
            
            return response()->json([
                'message' => 'Product featured status updated successfully',
                'product' => $product->fresh(['category', 'measurements'])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update product featured status: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Toggle the active status of a product.
     *
     * @param  int  $id
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function toggleStatus($id, Request $request)
    {
        try {
            $product = Product::findOrFail($id);
            
            // Update the active status based on the request or toggle the current value
            $isActive = $request->has('is_active') 
                ? $request->boolean('is_active') 
                : !$product->is_active;
            
            $product->is_active = $isActive;
            $product->save();
            
            // Clear cache after updating a product
            $this->clearProductCache();
            
            return response()->json([
                'message' => 'Product active status updated successfully',
                'product' => $product->fresh(['category', 'measurements'])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update product active status: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Bulk delete products.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function bulkDelete(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_ids' => 'required|array',
            'product_ids.*' => 'required|integer|exists:products,id'
        ]);
        
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        
        DB::beginTransaction();
        
        try {
            $products = Product::whereIn('id', $request->product_ids)->get();
            
            foreach ($products as $product) {
                // Delete related measurements
                $product->measurements()->delete();
                
                // Delete related images
                $product->images()->delete();
                
                // Delete the product
                $product->delete();
            }
            
            DB::commit();
            
            // Clear cache after bulk deleting products
            $this->clearProductCache();
            
            return response()->json([
                'message' => count($request->product_ids) . ' products deleted successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to delete products: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Bulk update featured status for products.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function bulkFeature(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_ids' => 'required|array',
            'product_ids.*' => 'required|integer|exists:products,id',
            'is_featured' => 'required|boolean'
        ]);
        
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        
        try {
            $count = Product::whereIn('id', $request->product_ids)
                ->update(['is_featured' => $request->boolean('is_featured')]);
            
            // Clear cache after bulk updating products
            $this->clearProductCache();
            
            return response()->json([
                'message' => $count . ' products updated successfully',
                'is_featured' => $request->boolean('is_featured')
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update products: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Bulk update active status for products.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function bulkStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_ids' => 'required|array',
            'product_ids.*' => 'required|integer|exists:products,id',
            'is_active' => 'required|boolean'
        ]);
        
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        
        try {
            $count = Product::whereIn('id', $request->product_ids)
                ->update(['is_active' => $request->boolean('is_active')]);
            
            // Clear cache after bulk updating products
            $this->clearProductCache();
            
            return response()->json([
                'message' => $count . ' products updated successfully',
                'is_active' => $request->boolean('is_active')
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update products: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get revenue data for dashboard
     */
    public function getRevenueData(Request $request)
    {
        try {
            $startDate = $request->query('start_date') ? 
                Carbon::parse($request->query('start_date')) : 
                Carbon::now()->subDays(30)->startOfDay();

            $query = DB::table('orders')
                ->where('status', '!=', 'cancelled')
                ->where('payment_status', 'paid')
                ->where('created_at', '>=', $startDate);

            // For 24-hour analysis, group by hour
            if ($startDate->diffInHours(Carbon::now()) <= 24) {
                $revenueData = $query
                    ->select(
                        DB::raw('DATE_FORMAT(created_at, "%Y-%m-%d %H:00:00") as date'),
                        DB::raw('SUM(grand_total) as revenue')
                    )
                    ->groupBy(DB::raw('HOUR(created_at)'))
                    ->orderBy('date')
                    ->get();

                // Fill in missing hours
                $filledData = [];
                $currentDate = Carbon::now();
                
                for ($i = 23; $i >= 0; $i--) {
                    $date = $currentDate->copy()->startOfHour()->subHours($i);
                    $dateKey = $date->format('Y-m-d H:00:00');
                    
                    $hourData = $revenueData->firstWhere('date', $dateKey);
                    $filledData[] = [
                        'date' => $dateKey,
                        'revenue' => $hourData ? $hourData->revenue : 0
                    ];
                }
            } else {
                $revenueData = $query
                    ->select(
                        DB::raw('DATE(created_at) as date'),
                        DB::raw('SUM(grand_total) as revenue')
                    )
                    ->groupBy(DB::raw('DATE(created_at)'))
                    ->orderBy('date')
                    ->get();

                // Fill in missing dates
                $filledData = [];
                $currentDate = Carbon::now()->startOfDay();
                $days = $startDate->diffInDays($currentDate);
                
                for ($i = $days; $i >= 0; $i--) {
                    $date = $currentDate->copy()->subDays($i)->format('Y-m-d');
                    $dayData = $revenueData->firstWhere('date', $date);
                    
                    $filledData[] = [
                        'date' => $date,
                        'revenue' => $dayData ? $dayData->revenue : 0
                    ];
                }
            }

            return response()->json([
                'status' => 'success',
                'data' => $filledData
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching revenue data: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch revenue data: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get peak and low days analysis
     */
    public function getPeakDays(Request $request)
    {
        try {
            $startDate = $request->query('start_date') ? 
                Carbon::parse($request->query('start_date')) : 
                Carbon::now()->subDays(30)->startOfDay();

            $query = DB::table('orders')
                ->where('status', '!=', 'cancelled')
                ->where('payment_status', 'paid')
                ->where('created_at', '>=', $startDate);

            // For 24-hour analysis, group by hour instead of day
            if ($startDate->diffInHours(Carbon::now()) <= 24) {
                // For 24-hour view, we want to ensure we're only using today's data
                // Get today's date
                $today = date('Y-m-d');
                
                // Get all today's orders directly
                $todayOrders = DB::table('orders')
                    ->where('status', '!=', 'cancelled')
                    ->where('payment_status', 'paid')
                    ->whereDate('created_at', '=', $today)
                    ->select('id', 'grand_total', 'created_at')
                    ->get();
                
                // Create a single entry for today with the actual count and revenue
                $dayName = date('l'); // Today's name (e.g., 'Saturday')
                $totalOrders = $todayOrders->count();
                $totalRevenue = $todayOrders->sum('grand_total');
                
                $daysData = collect([
                    (object)[
                        'day' => $dayName,
                        'order_count' => $totalOrders,
                        'revenue' => $totalRevenue,
                        'avg_order_value' => $totalOrders > 0 ? $totalRevenue / $totalOrders : 0
                    ]
                ]);
            } else {
                $daysData = $query
                    ->select(
                        DB::raw('DAYNAME(created_at) as day'),
                        DB::raw('COUNT(*) as order_count'),
                        DB::raw('SUM(grand_total) as revenue'),
                        DB::raw('AVG(grand_total) as avg_order_value')
                    )
                    ->groupBy('day')
                    ->orderBy(DB::raw('DAYOFWEEK(MIN(created_at))'))
                    ->get();

                // Fill in missing dates
                $filledData = [];
                $currentDate = Carbon::now()->startOfDay();
                $days = $startDate->diffInDays($currentDate);
                
                for ($i = $days; $i >= 0; $i--) {
                    $date = $currentDate->copy()->subDays($i)->format('l');
                    $dayData = $daysData->firstWhere('day', $date);
                    
                    $filledData[] = (object)[
                        'day' => $date,
                        'order_count' => $dayData ? $dayData->order_count : 0,
                        'revenue' => $dayData ? $dayData->revenue : 0,
                        'avg_order_value' => $dayData ? $dayData->avg_order_value : 0
                    ];
                }
                $daysData = collect($filledData);
            }

            // Calculate peak and low metrics
            $maxOrders = $daysData->max('order_count');
            $minOrders = $daysData->min('order_count');
            $maxRevenue = $daysData->max('revenue');
            $minRevenue = $daysData->min('revenue');

            $enhancedData = $daysData->map(function($day) use ($maxOrders, $minOrders, $maxRevenue, $minRevenue) {
                return [
                    'day' => $day->day,
                    'order_count' => $day->order_count,
                    'revenue' => $day->revenue,
                    'avg_order_value' => $day->avg_order_value,
                    'is_peak_orders' => $day->order_count == $maxOrders && $day->order_count > 0,
                    'is_low_orders' => $day->order_count == $minOrders,
                    'is_peak_revenue' => $day->revenue == $maxRevenue && $day->revenue > 0,
                    'is_low_revenue' => $day->revenue == $minRevenue,
                    'performance_score' => $maxOrders > 0 ? 
                        ($day->order_count / $maxOrders + ($maxRevenue > 0 ? $day->revenue / $maxRevenue : 0)) / 2 * 100 : 0
                ];
            });

            return response()->json([
                'status' => 'success',
                'data' => $enhancedData,
                'metrics' => [
                    'peak_orders_day' => $enhancedData->firstWhere('is_peak_orders', true)['day'] ?? null,
                    'low_orders_day' => $enhancedData->firstWhere('is_low_orders', true)['day'] ?? null,
                    'peak_revenue_day' => $enhancedData->firstWhere('is_peak_revenue', true)['day'] ?? null,
                    'low_revenue_day' => $enhancedData->firstWhere('is_low_revenue', true)['day'] ?? null,
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching peak days data: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch peak days data'
            ], 500);
        }
    }

    /**
     * Get peak and low hours analysis
     */
    public function getPeakHours(Request $request)
    {
        try {
            $startDate = $request->query('start_date') ? 
                Carbon::parse($request->query('start_date')) : 
                Carbon::now()->subDays(30)->startOfDay();
                
            $endDate = Carbon::now();
            $diffHours = $startDate->diffInHours($endDate);
            $timeRange = $diffHours <= 24 ? '24hours' : 
                         ($diffHours <= 168 ? '7days' : 
                         ($diffHours <= 720 ? '30days' : '90days'));

            $query = DB::table('orders')
                ->where('status', '!=', 'cancelled')
                ->where('payment_status', 'paid')
                ->where('created_at', '>=', $startDate);

            // Different analysis based on time range
            if ($timeRange === '24hours') {
                // For 24-hour view: Analyze specific hours within today only
                // Reset the query to explicitly filter by today's date
                $todayQuery = DB::table('orders')
                    ->where('status', '!=', 'cancelled')
                    ->where('payment_status', 'paid')
                    ->whereDate('created_at', '=', date('Y-m-d'));
                
                $hoursData = $todayQuery
                    ->select(
                        DB::raw('HOUR(created_at) as hour'),
                        DB::raw('DATE_FORMAT(created_at, "%H:00") as hour_formatted'),
                        DB::raw('COUNT(*) as order_count'),
                        DB::raw('SUM(grand_total) as revenue'),
                        DB::raw('AVG(grand_total) as avg_order_value')
                    )
                    ->groupBy(DB::raw('HOUR(created_at)'))
                    ->orderBy(DB::raw('HOUR(created_at)'))
                    ->get();

                // Fill in all hours for today (0-23) in sequential order
                $filledData = [];
                for ($i = 0; $i < 24; $i++) {
                    $hourData = $hoursData->firstWhere('hour', $i);
                    $filledData[] = (object)[
                        'hour' => $i,
                        'hour_formatted' => sprintf('%02d:00', $i),
                        'order_count' => $hourData ? $hourData->order_count : 0,
                        'revenue' => $hourData ? $hourData->revenue : 0,
                        'avg_order_value' => $hourData ? $hourData->avg_order_value : 0,
                        'time_range' => '24hours'
                    ];
                }
                // Sort by hour for consistent display
                usort($filledData, function($a, $b) {
                    return $a->hour - $b->hour;
                });
                $hoursData = collect($filledData);
            } 
            elseif ($timeRange === '7days') {
                // For 7-day view: Analyze day-hour combinations
                $rawData = $query
                    ->select(
                        DB::raw('DATE(created_at) as date'),
                        DB::raw('HOUR(created_at) as hour'),
                        DB::raw('DAYNAME(created_at) as day_name'),
                        DB::raw('COUNT(*) as order_count'),
                        DB::raw('SUM(grand_total) as revenue')
                    )
                    ->groupBy(DB::raw('DATE(created_at), HOUR(created_at), DAYNAME(created_at)'))
                    ->orderBy(DB::raw('DATE(created_at)'), 'desc')
                    ->orderBy(DB::raw('HOUR(created_at)'))
                    ->get();
                
                // Group by hour to find the most active hour across the week
                $hourlyAggregates = [];
                foreach ($rawData as $item) {
                    if (!isset($hourlyAggregates[$item->hour])) {
                        $hourlyAggregates[$item->hour] = [
                            'hour' => $item->hour,
                            'hour_formatted' => sprintf('%02d:00', $item->hour),
                            'order_count' => 0,
                            'revenue' => 0,
                            'days' => []
                        ];
                    }
                    
                    $hourlyAggregates[$item->hour]['order_count'] += $item->order_count;
                    $hourlyAggregates[$item->hour]['revenue'] += $item->revenue;
                    $hourlyAggregates[$item->hour]['days'][] = [
                        'date' => $item->date,
                        'day_name' => $item->day_name,
                        'order_count' => $item->order_count,
                        'revenue' => $item->revenue
                    ];
                }
                
                // Fill in missing hours
                $filledData = [];
                for ($i = 0; $i < 24; $i++) {
                    if (isset($hourlyAggregates[$i])) {
                        $hourlyAggregates[$i]['time_range'] = '7days';
                        // Add avg_order_value to prevent undefined property error
                        $hourlyAggregates[$i]['avg_order_value'] = $hourlyAggregates[$i]['order_count'] > 0 ? 
                            $hourlyAggregates[$i]['revenue'] / $hourlyAggregates[$i]['order_count'] : 0;
                        $filledData[] = (object)$hourlyAggregates[$i];
                    } else {
                        $filledData[] = (object)[
                            'hour' => $i,
                            'hour_formatted' => sprintf('%02d:00', $i),
                            'order_count' => 0,
                            'revenue' => 0,
                            'avg_order_value' => 0,
                            'days' => [],
                            'time_range' => '7days'
                        ];
                    }
                }
                $hoursData = collect($filledData);
            }
            else {
                // For 30/90-day view: Analyze hour patterns across the entire period
                $hoursData = $query
                    ->select(
                        DB::raw('HOUR(created_at) as hour'),
                        DB::raw('COUNT(*) as order_count'),
                        DB::raw('SUM(grand_total) as revenue'),
                        DB::raw('AVG(grand_total) as avg_order_value'),
                        DB::raw('COUNT(DISTINCT DATE(created_at)) as active_days')
                    )
                    ->groupBy('hour')
                    ->orderBy('hour')
                    ->get();

                // Calculate daily averages for more meaningful comparisons
                $totalDays = $startDate->diffInDays($endDate) + 1;
                
                // Fill in missing hours
                $filledData = [];
                for ($i = 0; $i < 24; $i++) {
                    $hourData = $hoursData->firstWhere('hour', $i);
                    $filledData[] = (object)[
                        'hour' => $i,
                        'hour_formatted' => sprintf('%02d:00', $i),
                        'order_count' => $hourData ? $hourData->order_count : 0,
                        'revenue' => $hourData ? $hourData->revenue : 0,
                        'avg_order_value' => $hourData ? $hourData->avg_order_value : 0,
                        'active_days' => $hourData ? $hourData->active_days : 0,
                        'daily_avg_orders' => $hourData ? round($hourData->order_count / $totalDays, 2) : 0,
                        'daily_avg_revenue' => $hourData ? round($hourData->revenue / $totalDays, 2) : 0,
                        'time_range' => $timeRange
                    ];
                }
                $hoursData = collect($filledData);
            }

            // Calculate metrics based on time range
            if ($timeRange === '24hours') {
                // For 24-hour view: Only consider hours with actual data
                $nonZeroHours = $hoursData->filter(function($item) {
                    return $item->order_count > 0;
                });
                
                $maxOrders = $nonZeroHours->isEmpty() ? 0 : $nonZeroHours->max('order_count');
                $minOrders = $nonZeroHours->isEmpty() ? 0 : $nonZeroHours->min('order_count');
                $maxRevenue = $nonZeroHours->isEmpty() ? 0 : $nonZeroHours->max('revenue');
                $minRevenue = $nonZeroHours->isEmpty() ? 0 : $nonZeroHours->min('revenue');
                
                // If no orders in the last 24 hours, use all hours
                if ($nonZeroHours->isEmpty()) {
                    $maxOrders = $hoursData->max('order_count');
                    $minOrders = $hoursData->min('order_count');
                    $maxRevenue = $hoursData->max('revenue');
                    $minRevenue = $hoursData->min('revenue');
                }
            } 
            elseif ($timeRange === '7days') {
                // For 7-day view: Consider specific day-hour combinations
                $maxOrders = $hoursData->max('order_count');
                $minOrders = $hoursData->filter(function($item) {
                    return $item->order_count > 0; // Only consider hours with orders
                })->min('order_count') ?? 0;
                $maxRevenue = $hoursData->max('revenue');
                $minRevenue = $hoursData->filter(function($item) {
                    return $item->revenue > 0; // Only consider hours with revenue
                })->min('revenue') ?? 0;
            }
            else {
                // For 30/90-day view: Use daily averages for more meaningful comparison
                $maxOrders = $hoursData->max('daily_avg_orders');
                $minOrders = $hoursData->filter(function($item) {
                    return $item->daily_avg_orders > 0; // Only consider hours with orders
                })->min('daily_avg_orders') ?? 0;
                $maxRevenue = $hoursData->max('daily_avg_revenue');
                $minRevenue = $hoursData->filter(function($item) {
                    return $item->daily_avg_revenue > 0; // Only consider hours with revenue
                })->min('daily_avg_revenue') ?? 0;
            }

            // Enhance data with peak/low indicators
            $enhancedData = $hoursData->map(function($item) use ($maxOrders, $minOrders, $maxRevenue, $minRevenue, $timeRange) {
                $baseData = [
                    'hour' => $item->hour,
                    'hour_formatted' => $item->hour_formatted,
                    'order_count' => $item->order_count,
                    'revenue' => $item->revenue,
                    'time_range' => $timeRange
                ];
                
                if ($timeRange === '24hours' || $timeRange === '7days') {
                    $baseData['is_peak_orders'] = $item->order_count == $maxOrders && $item->order_count > 0;
                    $baseData['is_low_orders'] = $item->order_count == $minOrders && $item->order_count > 0;
                    $baseData['is_peak_revenue'] = $item->revenue == $maxRevenue && $item->revenue > 0;
                    $baseData['is_low_revenue'] = $item->revenue == $minRevenue && $item->revenue > 0;
                    $baseData['avg_order_value'] = $item->avg_order_value;
                } else {
                    // For 30/90-day view, use daily averages
                    $baseData['daily_avg_orders'] = $item->daily_avg_orders;
                    $baseData['daily_avg_revenue'] = $item->daily_avg_revenue;
                    $baseData['active_days'] = $item->active_days;
                    $baseData['is_peak_orders'] = $item->daily_avg_orders == $maxOrders && $item->daily_avg_orders > 0;
                    $baseData['is_low_orders'] = $item->daily_avg_orders == $minOrders && $item->daily_avg_orders > 0;
                    $baseData['is_peak_revenue'] = $item->daily_avg_revenue == $maxRevenue && $item->daily_avg_revenue > 0;
                    $baseData['is_low_revenue'] = $item->daily_avg_revenue == $minRevenue && $item->daily_avg_revenue > 0;
                    // Add avg_order_value to prevent undefined property error
                    $baseData['avg_order_value'] = isset($item->avg_order_value) ? $item->avg_order_value : 
                        ($item->order_count > 0 ? $item->revenue / $item->order_count : 0);
                }
                
                // Calculate performance score
                if ($timeRange === '24hours' || $timeRange === '7days') {
                    $baseData['performance_score'] = $maxOrders > 0 ? 
                        ($item->order_count / $maxOrders + ($maxRevenue > 0 ? $item->revenue / $maxRevenue : 0)) / 2 * 100 : 0;
                } else {
                    $baseData['performance_score'] = $maxOrders > 0 ? 
                        ($item->daily_avg_orders / $maxOrders + ($maxRevenue > 0 ? $item->daily_avg_revenue / $maxRevenue : 0)) / 2 * 100 : 0;
                }
                
                return $baseData;
            });

            // Group into time segments
            $segments = [
                'morning' => [6, 11],
                'afternoon' => [12, 17],
                'evening' => [18, 23],
                'night' => [0, 5]
            ];

            $segmentAnalysis = [];
            
            // For 24-hour view, we need a completely different approach
            if ($timeRange === '24hours') {
                // Get today's date
                $today = date('Y-m-d');
                
                // Initialize all segments with zero values
                foreach ($segments as $name => $hours) {
                    $segmentAnalysis[$name] = [
                        'total_orders' => 0,
                        'total_revenue' => 0,
                        'avg_performance' => 0
                    ];
                }
                
                // Get all today's orders directly
                $todayOrders = DB::table('orders')
                    ->where('status', '!=', 'cancelled')
                    ->where('payment_status', 'paid')
                    ->whereDate('created_at', '=', $today)
                    ->select('id', 'grand_total', 'created_at')
                    ->get();
                
                // Manually assign each order to its segment
                foreach ($todayOrders as $order) {
                    $hour = (int)date('G', strtotime($order->created_at));
                    
                    // Determine which segment this hour belongs to
                    $segmentName = null;
                    foreach ($segments as $name => $hourRange) {
                        if ($hour >= $hourRange[0] && $hour <= $hourRange[1]) {
                            $segmentName = $name;
                            break;
                        }
                    }
                    
                    if ($segmentName) {
                        $segmentAnalysis[$segmentName]['total_orders'] += 1;
                        $segmentAnalysis[$segmentName]['total_revenue'] += $order->grand_total;
                    }
                }
            } else {
                // For other time ranges, use the enhanced data
                foreach ($segments as $name => $hours) {
                    $segmentData = $enhancedData->filter(function($item) use ($hours) {
                        return $item['hour'] >= $hours[0] && $item['hour'] <= $hours[1];
                    });
                    
                    if ($segmentData->isNotEmpty()) {
                        $segmentAnalysis[$name] = [
                            'total_orders' => $segmentData->sum('order_count'),
                            'total_revenue' => $segmentData->sum('revenue'),
                            'avg_performance' => $segmentData->avg('performance_score')
                        ];
                    } else {
                        $segmentAnalysis[$name] = [
                            'total_orders' => 0,
                            'total_revenue' => 0,
                            'avg_performance' => 0
                        ];
                    }
                }
            }

            // Find peak and low hours
            $peakOrdersHour = $enhancedData->firstWhere('is_peak_orders', true);
            $lowOrdersHour = $enhancedData->firstWhere('is_low_orders', true);
            $peakRevenueHour = $enhancedData->firstWhere('is_peak_revenue', true);
            $lowRevenueHour = $enhancedData->firstWhere('is_low_revenue', true);
            
            // Add time range to metrics
            return response()->json([
                'status' => 'success',
                'data' => $enhancedData,
                'segments' => $segmentAnalysis,
                'time_range' => $timeRange,
                'metrics' => [
                    'peak_orders_hour' => $peakOrdersHour ? $peakOrdersHour['hour_formatted'] : null,
                    'low_orders_hour' => $lowOrdersHour ? $lowOrdersHour['hour_formatted'] : null,
                    'peak_revenue_hour' => $peakRevenueHour ? $peakRevenueHour['hour_formatted'] : null,
                    'low_revenue_hour' => $lowRevenueHour ? $lowRevenueHour['hour_formatted'] : null,
                    'time_range' => $timeRange
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching peak hours data: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch peak hours data: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload a base64 encoded image to Cloudinary.
     *
     * @param string $base64Image
     * @param string $publicId
     * @return string
     */
    private function uploadBase64Image(string $base64Image, string $publicId): string
    {
        return $this->cloudinaryService->uploadBase64Image($base64Image, $publicId);
    }

    /**
     * Generate a placeholder image URL based on product name.
     *
     * @param string $productName
     * @return string
     */
    private function generatePlaceholderImage(string $productName): string
    {
        // Format the product name for the placeholder text
        $text = str_replace(' ', '\n', $productName);
        
        // Generate a placeholder image URL with the product name as text
        return $this->cloudinaryService->generatePlaceholderUrl($text);
    }

    /**
     * Get dashboard stats
     */
    /**
     * Get 7-day sales summary
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSevenDaySummary()
    {
        try {
            $endDate = Carbon::now();
            $startDate = Carbon::now()->subDays(7)->startOfDay();
            
            // Get total revenue for the last 7 days
            $totalRevenue = DB::table('orders')
                ->where('status', '!=', 'cancelled')
                ->where('payment_status', 'paid')
                ->where('created_at', '>=', $startDate)
                ->where('created_at', '<=', $endDate)
                ->sum('grand_total');
            
            // Get total number of orders for the last 7 days
            $totalOrders = DB::table('orders')
                ->where('status', '!=', 'cancelled')
                ->where('payment_status', 'paid')
                ->where('created_at', '>=', $startDate)
                ->where('created_at', '<=', $endDate)
                ->count();
            
            // Temporarily disable strict mode for GROUP BY
            DB::statement("SET SESSION sql_mode=''");
            
            // Get daily breakdown
            $dailyBreakdown = DB::table('orders')
                ->where('status', '!=', 'cancelled')
                ->where('payment_status', 'paid')
                ->where('created_at', '>=', $startDate)
                ->where('created_at', '<=', $endDate)
                ->select(
                    DB::raw('DATE(created_at) as date'),
                    DB::raw('DAYNAME(created_at) as day_name'),
                    DB::raw('COUNT(*) as order_count'),
                    DB::raw('SUM(grand_total) as revenue'),
                    DB::raw('AVG(grand_total) as avg_order_value')
                )
                ->groupBy(DB::raw('DATE(created_at), DAYNAME(created_at)'))
                ->orderBy('date')
                ->get();
                
            // We don't need to reset SQL mode here as it's done after the hourly query
            
            // Calculate average order value
            $avgOrderValue = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0;
            
            // Find peak day (highest revenue)
            $peakDay = $dailyBreakdown->isEmpty() ? null : $dailyBreakdown->sortByDesc('revenue')->first();
            
            // Find busiest day (most orders)
            $busiestDay = $dailyBreakdown->isEmpty() ? null : $dailyBreakdown->sortByDesc('order_count')->first();
            
            // Format daily data for peak days chart
            $formattedDailyData = [];
            foreach ($dailyBreakdown as $day) {
                $formattedDailyData[] = [
                    'day' => $day->day_name, // Renamed to match expected field name
                    'day_name' => $day->day_name,
                    'date' => $day->date,
                    'order_count' => $day->order_count,
                    'revenue' => $day->revenue,
                    'avg_order_value' => $day->avg_order_value,
                    'is_peak_orders' => $busiestDay && $day->date === $busiestDay->date,
                    'is_low_orders' => $day->order_count === $dailyBreakdown->where('order_count', '>', 0)->min('order_count'),
                    'is_peak_revenue' => $peakDay && $day->date === $peakDay->date,
                    'is_low_revenue' => $day->revenue == $dailyBreakdown->where('revenue', '>', 0)->min('revenue')
                ];
            }
            
            // Get hourly breakdown for peak hours chart
            // Using DB::statement to temporarily disable strict mode for this query
            DB::statement("SET SESSION sql_mode=''");
            
            $hourlyBreakdown = DB::table('orders')
                ->where('status', '!=', 'cancelled')
                ->where('payment_status', 'paid')
                ->where('created_at', '>=', $startDate)
                ->where('created_at', '<=', $endDate)
                ->select(
                    DB::raw('HOUR(created_at) as hour'),
                    DB::raw('DATE_FORMAT(created_at, "%H:00") as hour_formatted'),
                    DB::raw('COUNT(*) as order_count'),
                    DB::raw('SUM(grand_total) as revenue'),
                    DB::raw('AVG(grand_total) as avg_order_value')
                )
                ->groupBy(DB::raw('HOUR(created_at)'))
                ->orderBy('hour')
                ->get();
                
            // Reset SQL mode back to default
            DB::statement("SET SESSION sql_mode='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
            
            // Find peak and low hours
            $peakHour = $hourlyBreakdown->isEmpty() ? null : $hourlyBreakdown->sortByDesc('revenue')->first();
            $lowHour = $hourlyBreakdown->isEmpty() ? null : $hourlyBreakdown->where('revenue', '>', 0)->sortBy('revenue')->first();
            $peakOrderHour = $hourlyBreakdown->isEmpty() ? null : $hourlyBreakdown->sortByDesc('order_count')->first();
            $lowOrderHour = $hourlyBreakdown->isEmpty() ? null : $hourlyBreakdown->where('order_count', '>', 0)->sortBy('order_count')->first();
            
            // Format hourly data for peak hours chart
            $formattedHourlyData = [];
            foreach ($hourlyBreakdown as $hour) {
                $formattedHourlyData[] = [
                    'hour' => $hour->hour,
                    'hour_formatted' => $hour->hour_formatted,
                    'order_count' => $hour->order_count,
                    'revenue' => $hour->revenue,
                    'avg_order_value' => $hour->avg_order_value,
                    'is_peak_orders' => $peakOrderHour && $hour->hour === $peakOrderHour->hour && $hour->order_count > 0,
                    'is_low_orders' => $lowOrderHour && $hour->hour === $lowOrderHour->hour && $hour->order_count > 0,
                    'is_peak_revenue' => $peakHour && $hour->hour === $peakHour->hour && $hour->revenue > 0,
                    'is_low_revenue' => $lowHour && $hour->hour === $lowHour->hour && $hour->revenue > 0,
                    'time_range' => '7days'
                ];
            }
            
            // Create segment analysis data
            // Define time segments
            $segments = [
                'morning' => ['name' => 'Morning', 'start' => 6, 'end' => 11], // 6:00 - 11:59
                'afternoon' => ['name' => 'Afternoon', 'start' => 12, 'end' => 17], // 12:00 - 17:59
                'evening' => ['name' => 'Evening', 'start' => 18, 'end' => 23], // 18:00 - 23:59
                'night' => ['name' => 'Night', 'start' => 0, 'end' => 5], // 0:00 - 5:59
            ];
            
            // Group hourly data into segments
            $segmentData = [];
            foreach ($segments as $key => $segment) {
                $segmentHours = $hourlyBreakdown->filter(function($hour) use ($segment) {
                    return $hour->hour >= $segment['start'] && $hour->hour <= $segment['end'];
                });
                
                $segmentOrderCount = $segmentHours->sum('order_count');
                $segmentRevenue = $segmentHours->sum('revenue');
                
                $segmentData[$key] = [
                    'name' => $segment['name'],
                    'order_count' => $segmentOrderCount,
                    'revenue' => $segmentRevenue,
                    'avg_order_value' => $segmentOrderCount > 0 ? $segmentRevenue / $segmentOrderCount : 0
                ];
            }
            
            // Format segment data for frontend
            $formattedSegments = [
                'morning' => $segmentData['morning'],
                'afternoon' => $segmentData['afternoon'],
                'evening' => $segmentData['evening'],
                'night' => $segmentData['night'],
                'peak_hour' => $peakOrderHour ? [
                    'hour' => $peakOrderHour->hour,
                    'hour_formatted' => $peakOrderHour->hour_formatted,
                    'order_count' => $peakOrderHour->order_count,
                    'revenue' => $peakOrderHour->revenue,
                    'label' => 'Busiest hour across all 7 days'
                ] : null,
                'low_hour' => $lowOrderHour ? [
                    'hour' => $lowOrderHour->hour,
                    'hour_formatted' => $lowOrderHour->hour_formatted,
                    'order_count' => $lowOrderHour->order_count,
                    'revenue' => $lowOrderHour->revenue,
                    'label' => 'Quietest hour across all 7 days'
                ] : null
            ];
            
            // Create metrics for peak hours
            $peakHoursMetrics = [
                'peak_orders_hour' => $peakOrderHour ? $peakOrderHour->hour_formatted : null,
                'peak_revenue_hour' => $peakHour ? $peakHour->hour_formatted : null,
                'low_orders_hour' => $lowOrderHour ? $lowOrderHour->hour_formatted : null,
                'low_revenue_hour' => $lowHour ? $lowHour->hour_formatted : null,
                'time_range' => '7days'
            ];
            
            // Create metrics for peak days
            $peakDaysMetrics = [
                'peak_orders_day' => $busiestDay ? $busiestDay->day_name : null,
                'peak_revenue_day' => $peakDay ? $peakDay->day_name : null,
                'low_orders_day' => $dailyBreakdown->where('order_count', '>', 0)->sortBy('order_count')->first() ? 
                    $dailyBreakdown->where('order_count', '>', 0)->sortBy('order_count')->first()->day_name : null,
                'low_revenue_day' => $dailyBreakdown->where('revenue', '>', 0)->sortBy('revenue')->first() ? 
                    $dailyBreakdown->where('revenue', '>', 0)->sortBy('revenue')->first()->day_name : null,
                'time_range' => '7days'
            ];
            
            // Calculate performance indicators by comparing to previous period
            $previousPeriodStart = $startDate->copy()->subDays(7);
            $previousPeriodEnd = $endDate->copy()->subDays(7);
            
            // Get previous period data
            $previousPeriodOrders = Order::whereBetween('created_at', [$previousPeriodStart, $previousPeriodEnd])
                ->where('status', '!=', 'cancelled')
                ->where('payment_status', 'paid')
                ->count();
                
            $previousPeriodRevenue = Order::whereBetween('created_at', [$previousPeriodStart, $previousPeriodEnd])
                ->where('status', '!=', 'cancelled')
                ->where('payment_status', 'paid')
                ->sum('grand_total');
            
            // Calculate percentage changes
            $revenueChange = $previousPeriodRevenue > 0 ? 
                (($totalRevenue - $previousPeriodRevenue) / $previousPeriodRevenue) * 100 : 
                ($totalRevenue > 0 ? 100 : 0);
                
            $ordersChange = $previousPeriodOrders > 0 ? 
                (($totalOrders - $previousPeriodOrders) / $previousPeriodOrders) * 100 : 
                ($totalOrders > 0 ? 100 : 0);
            
            // Create performance indicator
            $performanceIndicator = [
                'revenue_change_percent' => round($revenueChange, 2),
                'orders_change_percent' => round($ordersChange, 2),
                'previous_period_revenue' => $previousPeriodRevenue,
                'previous_period_orders' => $previousPeriodOrders,
                'overall_status' => $revenueChange >= 0 ? 'positive' : 'negative'
            ];
            
            
            return response()->json([
                'status' => 'success',
                'data' => [
                    'total_revenue' => $totalRevenue,
                    'total_orders' => $totalOrders,
                    'avg_order_value' => $avgOrderValue,
                    'peak_day' => $peakDay,
                    'busiest_day' => $busiestDay,
                    'daily_breakdown' => $dailyBreakdown,
                    'peak_days_data' => $formattedDailyData, // For peak days chart
                    'peak_hours_data' => $formattedHourlyData, // For peak hours chart
                    'segments' => $formattedSegments, // For segment analysis
                    'metrics' => [
                        'hours' => $peakHoursMetrics, // For peak hours metrics
                        'days' => $peakDaysMetrics // For peak days metrics
                    ],
                    'performance_indicator' => $performanceIndicator, // Business performance indicator
                    'date_range' => [
                        'start' => $startDate->toDateTimeString(),
                        'end' => $endDate->toDateTimeString()
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching 7-day summary: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch 7-day summary: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get 30-day sales summary
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getThirtyDaySummary()
    {
        try {
            $endDate = Carbon::now();
            $startDate = Carbon::now()->subDays(30)->startOfDay();
            
            // Get total revenue for the last 30 days
            $totalRevenue = DB::table('orders')
                ->where('status', '!=', 'cancelled')
                ->where('payment_status', 'paid')
                ->where('created_at', '>=', $startDate)
                ->where('created_at', '<=', $endDate)
                ->sum('grand_total');
            
            // Get total number of orders for the last 30 days
            $totalOrders = DB::table('orders')
                ->where('status', '!=', 'cancelled')
                ->where('payment_status', 'paid')
                ->where('created_at', '>=', $startDate)
                ->where('created_at', '<=', $endDate)
                ->count();
            
            // Temporarily disable strict mode for GROUP BY
            DB::statement("SET SESSION sql_mode=''");
            
            // Get daily breakdown data
            $dailyBreakdown = DB::table('orders')
                ->select(
                    DB::raw('DATE(created_at) as date'),
                    DB::raw('COUNT(*) as orders'),
                    DB::raw('SUM(grand_total) as revenue')
                )
                ->where('status', '!=', 'cancelled')
                ->where('payment_status', 'paid')
                ->where('created_at', '>=', $startDate)
                ->where('created_at', '<=', $endDate)
                ->groupBy('date')
                ->orderBy('date', 'asc')
                ->get();
            
            // Get hourly breakdown data
            $hourlyBreakdown = DB::table('orders')
                ->select(
                    DB::raw('HOUR(created_at) as hour'),
                    DB::raw('COUNT(*) as orders'),
                    DB::raw('SUM(grand_total) as revenue')
                )
                ->where('status', '!=', 'cancelled')
                ->where('payment_status', 'paid')
                ->where('created_at', '>=', $startDate)
                ->where('created_at', '<=', $endDate)
                ->groupBy('hour')
                ->orderBy('hour', 'asc')
                ->get();
            
            // Re-enable strict mode
            DB::statement("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION'");
            
            // Find peak day (highest revenue)
            $peakDay = $dailyBreakdown->isEmpty() ? null : $dailyBreakdown->sortByDesc('revenue')->first();
            
            // Find busiest day (most orders)
            $busiestDay = $dailyBreakdown->isEmpty() ? null : $dailyBreakdown->sortByDesc('orders')->first();
            
            // Format daily data for peak days chart
            $formattedDailyData = [];
            foreach ($dailyBreakdown as $day) {
                $formattedDailyData[] = [
                    'day' => Carbon::parse($day->date)->format('l'),
                    'day_name' => Carbon::parse($day->date)->format('l'),
                    'date' => $day->date,
                    'order_count' => $day->orders,
                    'revenue' => $day->revenue,
                    'avg_order_value' => $day->orders > 0 ? $day->revenue / $day->orders : 0,
                    'is_peak_orders' => $busiestDay && $day->date === $busiestDay->date,
                    'is_low_orders' => false, // Will be set properly later
                    'is_peak_revenue' => $peakDay && $day->date === $peakDay->date,
                    'is_low_revenue' => false // Will be set properly later
                ];
            }
            
            // Use formatted data for peak days
            $peakDaysData = collect($formattedDailyData);
            
            // Calculate low days
            $lowDaysData = $dailyBreakdown->sortBy('revenue')->take(3)->values();
            
            // Find peak and low hours
            $peakHour = $hourlyBreakdown->isEmpty() ? null : $hourlyBreakdown->sortByDesc('revenue')->first();
            $lowHour = $hourlyBreakdown->isEmpty() ? null : $hourlyBreakdown->where('revenue', '>', 0)->sortBy('revenue')->first();
            $peakOrderHour = $hourlyBreakdown->isEmpty() ? null : $hourlyBreakdown->sortByDesc('orders')->first();
            $lowOrderHour = $hourlyBreakdown->isEmpty() ? null : $hourlyBreakdown->where('orders', '>', 0)->sortBy('orders')->first();
            
            // Format hourly data for peak hours chart
            $formattedHourlyData = [];
            foreach ($hourlyBreakdown as $hour) {
                $formattedHourlyData[] = [
                    'hour' => $hour->hour,
                    'hour_formatted' => sprintf('%02d:00', $hour->hour),
                    'order_count' => $hour->orders,
                    'revenue' => $hour->revenue,
                    'avg_order_value' => $hour->orders > 0 ? $hour->revenue / $hour->orders : 0,
                    'is_peak_orders' => $peakOrderHour && $hour->hour === $peakOrderHour->hour && $hour->orders > 0,
                    'is_low_orders' => $lowOrderHour && $hour->hour === $lowOrderHour->hour && $hour->orders > 0,
                    'is_peak_revenue' => $peakHour && $hour->hour === $peakHour->hour && $hour->revenue > 0,
                    'is_low_revenue' => $lowHour && $hour->hour === $lowHour->hour && $hour->revenue > 0,
                    'time_range' => '30days'
                ];
            }
            
            // Use formatted data for peak hours
            $peakHoursData = collect($formattedHourlyData);
            
            // Calculate average order value
            $avgOrderValue = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0;
            
            // Calculate segment analysis (morning, afternoon, evening, night)
            // Define time segments
            $segmentRanges = [
                'morning' => ['name' => 'Morning', 'start' => 6, 'end' => 11], // 6:00 - 11:59
                'afternoon' => ['name' => 'Afternoon', 'start' => 12, 'end' => 17], // 12:00 - 17:59
                'evening' => ['name' => 'Evening', 'start' => 18, 'end' => 23], // 18:00 - 23:59
                'night' => ['name' => 'Night', 'start' => 0, 'end' => 5], // 0:00 - 5:59
            ];
            
            // Initialize segments with proper structure
            $segmentData = [];
            foreach ($segmentRanges as $key => $segment) {
                $segmentHours = $hourlyBreakdown->filter(function($hour) use ($segment) {
                    return $hour->hour >= $segment['start'] && $hour->hour <= $segment['end'];
                });
                
                $segmentOrderCount = $segmentHours->sum('orders');
                $segmentRevenue = $segmentHours->sum('revenue');
                
                $segmentData[$key] = [
                    'name' => $segment['name'],
                    'order_count' => $segmentOrderCount,
                    'revenue' => $segmentRevenue,
                    'avg_order_value' => $segmentOrderCount > 0 ? $segmentRevenue / $segmentOrderCount : 0
                ];
            }
            
            // Format segments for frontend
            $segments = [
                'morning' => $segmentData['morning'],
                'afternoon' => $segmentData['afternoon'],
                'evening' => $segmentData['evening'],
                'night' => $segmentData['night'],
                'peak_hour' => $peakHour ? [
                    'hour' => $peakHour->hour,
                    'hour_formatted' => sprintf('%02d:00', $peakHour->hour),
                    'order_count' => $peakHour->orders,
                    'revenue' => $peakHour->revenue,
                    'label' => 'Busiest hour across all 30 days'
                ] : null,
                'low_hour' => $lowHour ? [
                    'hour' => $lowHour->hour,
                    'hour_formatted' => sprintf('%02d:00', $lowHour->hour),
                    'order_count' => $lowHour->orders,
                    'revenue' => $lowHour->revenue,
                    'label' => 'Quietest hour across all 30 days'
                ] : null
            ];
            
            // Calculate performance indicators (compare with previous 30 days)
            $previousStartDate = Carbon::now()->subDays(60)->startOfDay();
            $previousEndDate = Carbon::now()->subDays(30)->endOfDay();
            
            $previousRevenue = DB::table('orders')
                ->where('status', '!=', 'cancelled')
                ->where('payment_status', 'paid')
                ->where('created_at', '>=', $previousStartDate)
                ->where('created_at', '<=', $previousEndDate)
                ->sum('grand_total');
            
            $previousOrders = DB::table('orders')
                ->where('status', '!=', 'cancelled')
                ->where('payment_status', 'paid')
                ->where('created_at', '>=', $previousStartDate)
                ->where('created_at', '<=', $previousEndDate)
                ->count();
            
            $revenueChangePercent = $previousRevenue > 0 ? round((($totalRevenue - $previousRevenue) / $previousRevenue) * 100, 2) : 0;
            $ordersChangePercent = $previousOrders > 0 ? round((($totalOrders - $previousOrders) / $previousOrders) * 100, 2) : 0;
            
            $performanceIndicator = [
                'revenue_change_percent' => $revenueChangePercent,
                'orders_change_percent' => $ordersChangePercent,
                'overall_status' => $revenueChangePercent >= 0 ? 'positive' : 'negative'
            ];
            
            // Format dates in daily breakdown
            $formattedDailyBreakdown = $dailyBreakdown->map(function ($day) {
                return [
                    'date' => Carbon::parse($day->date)->format('Y-m-d'),
                    'orders' => $day->orders,
                    'revenue' => $day->revenue
                ];
            });
            
            // Create metrics for peak hours in standardized format (matching 7-day summary)
            $peakHoursMetrics = [
                'peak_orders_hour' => $peakOrderHour ? 
                    sprintf('%02d:00', $peakOrderHour->hour) : null,
                'peak_revenue_hour' => $peakHour ? 
                    sprintf('%02d:00', $peakHour->hour) : null,
                'low_orders_hour' => $lowOrderHour ? 
                    sprintf('%02d:00', $lowOrderHour->hour) : null,
                'low_revenue_hour' => $lowHour ? 
                    sprintf('%02d:00', $lowHour->hour) : null,
                'time_range' => '30days'
            ];
            
            // Create metrics for peak days in standardized format
            $peakDaysMetrics = [
                'peak_orders_day' => $busiestDay ? 
                    Carbon::parse($busiestDay->date)->format('l') : null,
                'peak_revenue_day' => $peakDay ? 
                    Carbon::parse($peakDay->date)->format('l') : null,
                'low_orders_day' => $dailyBreakdown->where('orders', '>', 0)->sortBy('orders')->first() ? 
                    Carbon::parse($dailyBreakdown->where('orders', '>', 0)->sortBy('orders')->first()->date)->format('l') : null,
                'low_revenue_day' => $dailyBreakdown->where('revenue', '>', 0)->sortBy('revenue')->first() ? 
                    Carbon::parse($dailyBreakdown->where('revenue', '>', 0)->sortBy('revenue')->first()->date)->format('l') : null,
                'time_range' => '30days'
            ];
            
            // Find the single day with the lowest orders (prioritizing the earliest date if there are ties)
            $minOrdersValue = $dailyBreakdown->where('orders', '>', 0)->min('orders');
            $lowOrdersDays = $dailyBreakdown->where('orders', $minOrdersValue)->sortBy('date');
            $lowOrdersDay = $lowOrdersDays->first();
            
            // Find the single day with the lowest revenue (prioritizing the earliest date if there are ties)
            $minRevenueValue = $dailyBreakdown->where('revenue', '>', 0)->min('revenue');
            $lowRevenueDays = $dailyBreakdown->where('revenue', $minRevenueValue)->sortBy('date');
            $lowRevenueDay = $lowRevenueDays->first();
            
            // Update the flags for low orders and low revenue days in formattedDailyData
            foreach ($formattedDailyData as &$day) {
                if ($lowOrdersDay && $day['date'] === $lowOrdersDay->date) {
                    $day['is_low_orders'] = true;
                }
                if ($lowRevenueDay && $day['date'] === $lowRevenueDay->date) {
                    $day['is_low_revenue'] = true;
                }
            }
            
            // Combine metrics
            $metrics = [
                'hours' => $peakHoursMetrics,
                'days' => $peakDaysMetrics
            ];
            
            return response()->json([
                'status' => 'success',
                'data' => [
                    'total_revenue' => $totalRevenue,
                    'total_orders' => $totalOrders,
                    'avg_order_value' => $avgOrderValue,
                    'daily_breakdown' => $formattedDailyBreakdown,
                    'hourly_breakdown' => $hourlyBreakdown,
                    'peak_days_data' => $peakDaysData,
                    'peak_hours_data' => $peakHoursData,
                    'segments' => $segments,
                    'metrics' => $metrics,
                    'performance_indicator' => $performanceIndicator
                ]
            ]);
            
        } catch (\Exception $e) {
            \Log::error('30-day summary error: ' . $e->getMessage() . '\nFile: ' . $e->getFile() . '\nLine: ' . $e->getLine() . '\nTrace: ' . $e->getTraceAsString());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch 30-day summary: ' . $e->getMessage() . ' at line ' . $e->getLine() . ' in ' . basename($e->getFile())
            ], 500);
        }
    }
    
    /**
     * Get 90-day sales summary
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getNinetyDaySummary()
    {
        try {
            $endDate = Carbon::now();
            $startDate = Carbon::now()->subDays(90)->startOfDay();
            
            // Get total revenue for the last 90 days
            $totalRevenue = DB::table('orders')
                ->where('status', '!=', 'cancelled')
                ->where('payment_status', 'paid')
                ->where('created_at', '>=', $startDate)
                ->where('created_at', '<=', $endDate)
                ->sum('grand_total');
            
            // Get total number of orders for the last 90 days
            $totalOrders = DB::table('orders')
                ->where('status', '!=', 'cancelled')
                ->where('payment_status', 'paid')
                ->where('created_at', '>=', $startDate)
                ->where('created_at', '<=', $endDate)
                ->count();
            
            // Temporarily disable strict mode for GROUP BY
            DB::statement("SET SESSION sql_mode=''");
            
            // Get daily breakdown
            $dailyBreakdown = DB::table('orders')
                ->where('status', '!=', 'cancelled')
                ->where('payment_status', 'paid')
                ->where('created_at', '>=', $startDate)
                ->where('created_at', '<=', $endDate)
                ->select(
                    DB::raw('DATE(created_at) as date'),
                    DB::raw('DAYNAME(created_at) as day_name'),
                    DB::raw('COUNT(*) as order_count'),
                    DB::raw('SUM(grand_total) as revenue'),
                    DB::raw('AVG(grand_total) as avg_order_value')
                )
                ->groupBy(DB::raw('DATE(created_at), DAYNAME(created_at)'))
                ->orderBy('date')
                ->get();
                
            // Calculate average order value
            $avgOrderValue = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0;
            
            // Find peak day (highest revenue)
            $peakDay = $dailyBreakdown->isEmpty() ? null : $dailyBreakdown->sortByDesc('revenue')->first();
            
            // Find busiest day (most orders)
            $busiestDay = $dailyBreakdown->isEmpty() ? null : $dailyBreakdown->sortByDesc('order_count')->first();
            
            // Format daily data for peak days chart
            $formattedDailyData = [];
            foreach ($dailyBreakdown as $day) {
                $formattedDailyData[] = [
                    'day' => $day->day_name, // Renamed to match expected field name
                    'day_name' => $day->day_name,
                    'date' => $day->date,
                    'order_count' => $day->order_count,
                    'revenue' => $day->revenue,
                    'avg_order_value' => $day->avg_order_value,
                    'is_peak_orders' => $busiestDay && $day->date === $busiestDay->date,
                    'is_low_orders' => $day->order_count === $dailyBreakdown->where('order_count', '>', 0)->min('order_count'),
                    'is_peak_revenue' => $peakDay && $day->date === $peakDay->date,
                    'is_low_revenue' => $day->revenue == $dailyBreakdown->where('revenue', '>', 0)->min('revenue')
                ];
            }
            
            // Get hourly breakdown for peak hours chart
            $hourlyBreakdown = DB::table('orders')
                ->where('status', '!=', 'cancelled')
                ->where('payment_status', 'paid')
                ->where('created_at', '>=', $startDate)
                ->where('created_at', '<=', $endDate)
                ->select(
                    DB::raw('HOUR(created_at) as hour'),
                    DB::raw('DATE_FORMAT(created_at, "%H:00") as hour_formatted'),
                    DB::raw('COUNT(*) as order_count'),
                    DB::raw('SUM(grand_total) as revenue'),
                    DB::raw('AVG(grand_total) as avg_order_value')
                )
                ->groupBy(DB::raw('HOUR(created_at)'))
                ->orderBy('hour')
                ->get();
                
            // Reset SQL mode back to default
            DB::statement("SET SESSION sql_mode='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
            
            // Find peak and low hours
            $peakHour = $hourlyBreakdown->isEmpty() ? null : $hourlyBreakdown->sortByDesc('revenue')->first();
            $lowHour = $hourlyBreakdown->isEmpty() ? null : $hourlyBreakdown->where('revenue', '>', 0)->sortBy('revenue')->first();
            $peakOrderHour = $hourlyBreakdown->isEmpty() ? null : $hourlyBreakdown->sortByDesc('order_count')->first();
            $lowOrderHour = $hourlyBreakdown->isEmpty() ? null : $hourlyBreakdown->where('order_count', '>', 0)->sortBy('order_count')->first();
            
            // Format hourly data for peak hours chart
            $formattedHourlyData = [];
            foreach ($hourlyBreakdown as $hour) {
                $formattedHourlyData[] = [
                    'hour' => $hour->hour,
                    'hour_formatted' => $hour->hour_formatted,
                    'order_count' => $hour->order_count,
                    'revenue' => $hour->revenue,
                    'avg_order_value' => $hour->avg_order_value,
                    'is_peak_orders' => $peakOrderHour && $hour->hour === $peakOrderHour->hour && $hour->order_count > 0,
                    'is_low_orders' => $lowOrderHour && $hour->hour === $lowOrderHour->hour && $hour->order_count > 0,
                    'is_peak_revenue' => $peakHour && $hour->hour === $peakHour->hour && $hour->revenue > 0,
                    'is_low_revenue' => $lowHour && $hour->hour === $lowHour->hour && $hour->revenue > 0,
                    'time_range' => '90days'
                ];
            }
            
            // Create segment analysis data
            // Define time segments
            $segments = [
                'morning' => ['name' => 'Morning', 'start' => 6, 'end' => 11], // 6:00 - 11:59
                'afternoon' => ['name' => 'Afternoon', 'start' => 12, 'end' => 17], // 12:00 - 17:59
                'evening' => ['name' => 'Evening', 'start' => 18, 'end' => 23], // 18:00 - 23:59
                'night' => ['name' => 'Night', 'start' => 0, 'end' => 5], // 0:00 - 5:59
            ];
            
            // Group hourly data into segments
            $segmentData = [];
            foreach ($segments as $key => $segment) {
                $segmentHours = $hourlyBreakdown->filter(function($hour) use ($segment) {
                    return $hour->hour >= $segment['start'] && $hour->hour <= $segment['end'];
                });
                
                $segmentOrderCount = $segmentHours->sum('order_count');
                $segmentRevenue = $segmentHours->sum('revenue');
                
                $segmentData[$key] = [
                    'name' => $segment['name'],
                    'order_count' => $segmentOrderCount,
                    'revenue' => $segmentRevenue,
                    'avg_order_value' => $segmentOrderCount > 0 ? $segmentRevenue / $segmentOrderCount : 0
                ];
            }
            
            // Format segment data for frontend
            $formattedSegments = [
                'morning' => $segmentData['morning'],
                'afternoon' => $segmentData['afternoon'],
                'evening' => $segmentData['evening'],
                'night' => $segmentData['night'],
                'peak_hour' => $peakOrderHour ? [
                    'hour' => $peakOrderHour->hour,
                    'hour_formatted' => $peakOrderHour->hour_formatted,
                    'order_count' => $peakOrderHour->order_count,
                    'revenue' => $peakOrderHour->revenue,
                    'label' => 'Busiest hour across all 90 days'
                ] : null,
                'low_hour' => $lowOrderHour ? [
                    'hour' => $lowOrderHour->hour,
                    'hour_formatted' => $lowOrderHour->hour_formatted,
                    'order_count' => $lowOrderHour->order_count,
                    'revenue' => $lowOrderHour->revenue,
                    'label' => 'Quietest hour across all 90 days'
                ] : null
            ];
            
            // Create metrics for peak hours
            $peakHoursMetrics = [
                'peak_orders_hour' => $peakOrderHour ? $peakOrderHour->hour_formatted : null,
                'peak_revenue_hour' => $peakHour ? $peakHour->hour_formatted : null,
                'low_orders_hour' => $lowOrderHour ? $lowOrderHour->hour_formatted : null,
                'low_revenue_hour' => $lowHour ? $lowHour->hour_formatted : null,
                'time_range' => '90days'
            ];
            
            // Create metrics for peak days
            $peakDaysMetrics = [
                'peak_orders_day' => $busiestDay ? $busiestDay->day_name : null,
                'peak_revenue_day' => $peakDay ? $peakDay->day_name : null,
                'low_orders_day' => $dailyBreakdown->where('order_count', '>', 0)->sortBy('order_count')->first() ? 
                    $dailyBreakdown->where('order_count', '>', 0)->sortBy('order_count')->first()->day_name : null,
                'low_revenue_day' => $dailyBreakdown->where('revenue', '>', 0)->sortBy('revenue')->first() ? 
                    $dailyBreakdown->where('revenue', '>', 0)->sortBy('revenue')->first()->day_name : null,
                'time_range' => '90days'
            ];
            
            // Calculate performance indicators by comparing to previous 90 days
            $previousPeriodStart = $startDate->copy()->subDays(90);
            $previousPeriodEnd = $endDate->copy()->subDays(90);
            
            // Get previous period data
            $previousPeriodOrders = DB::table('orders')
                ->whereBetween('created_at', [$previousPeriodStart, $previousPeriodEnd])
                ->where('status', '!=', 'cancelled')
                ->where('payment_status', 'paid')
                ->count();
                
            $previousPeriodRevenue = DB::table('orders')
                ->whereBetween('created_at', [$previousPeriodStart, $previousPeriodEnd])
                ->where('status', '!=', 'cancelled')
                ->where('payment_status', 'paid')
                ->sum('grand_total');
            
            // Calculate percentage changes
            $revenueChange = $previousPeriodRevenue > 0 ? 
                (($totalRevenue - $previousPeriodRevenue) / $previousPeriodRevenue) * 100 : 
                ($totalRevenue > 0 ? 100 : 0);
                
            $ordersChange = $previousPeriodOrders > 0 ? 
                (($totalOrders - $previousPeriodOrders) / $previousPeriodOrders) * 100 : 
                ($totalOrders > 0 ? 100 : 0);
            
            // Create performance indicator
            $performanceIndicator = [
                'revenue_change_percent' => round($revenueChange, 2),
                'orders_change_percent' => round($ordersChange, 2),
                'previous_period_revenue' => $previousPeriodRevenue,
                'previous_period_orders' => $previousPeriodOrders,
                'overall_status' => $revenueChange >= 0 ? 'positive' : 'negative'
            ];
            
            return response()->json([
                'status' => 'success',
                'data' => [
                    'total_revenue' => $totalRevenue,
                    'total_orders' => $totalOrders,
                    'avg_order_value' => $avgOrderValue,
                    'peak_day' => $peakDay,
                    'busiest_day' => $busiestDay,
                    'daily_breakdown' => $dailyBreakdown,
                    'peak_days_data' => $formattedDailyData, // For peak days chart
                    'peak_hours_data' => $formattedHourlyData, // For peak hours chart
                    'segments' => $formattedSegments, // For segment analysis
                    'metrics' => [
                        'hours' => $peakHoursMetrics, // For peak hours metrics
                        'days' => $peakDaysMetrics // For peak days metrics
                    ],
                    'performance_indicator' => $performanceIndicator, // Business performance indicator
                    'date_range' => [
                        'start' => $startDate->toDateTimeString(),
                        'end' => $endDate->toDateTimeString()
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching 90-day summary: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch 90-day summary: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all-time sales summary
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAllTimeSummary()
    {
        try {
            $endDate = Carbon::now();
            // Get the date of the first order or use a very old date if no orders exist
            $firstOrder = DB::table('orders')->orderBy('created_at', 'asc')->first();
            $startDate = $firstOrder ? Carbon::parse($firstOrder->created_at) : Carbon::now()->subYears(10);
            
            // Get total revenue for all time (with date range filter for consistency)
            $totalRevenue = DB::table('orders')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->where('status', '!=', 'cancelled')
                ->where('payment_status', 'paid')
                ->sum('grand_total');
            
            // Get total number of orders for all time (with date range filter for consistency)
            $totalOrders = DB::table('orders')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->where('status', '!=', 'cancelled')
                ->where('payment_status', 'paid')
                ->count();
            
            // Log the exact query details for debugging
            \Log::info('All-Time Summary Debug:', [
                'date_range' => [
                    'start' => $startDate->toDateTimeString(),
                    'end' => $endDate->toDateTimeString()
                ],
                'total_orders_with_filter' => $totalOrders,
                'total_revenue_with_filter' => $totalRevenue,
                'total_orders_without_payment_filter' => DB::table('orders')
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->where('status', '!=', 'cancelled')
                    ->count(),
                'total_revenue_without_payment_filter' => DB::table('orders')
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->where('status', '!=', 'cancelled')
                    ->sum('grand_total')
            ]);
            
            // Temporarily disable strict mode for GROUP BY
            DB::statement("SET SESSION sql_mode=''");
            
            // Get monthly breakdown instead of daily (since all-time daily would be too much data)
            $monthlyBreakdown = DB::table('orders')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->where('status', '!=', 'cancelled')
                ->where('payment_status', 'paid')
                ->select(
                    DB::raw('DATE_FORMAT(created_at, "%Y-%m") as month'),
                    DB::raw('MONTHNAME(created_at) as month_name'),
                    DB::raw('YEAR(created_at) as year'),
                    DB::raw('COUNT(*) as order_count'),
                    DB::raw('SUM(grand_total) as revenue'),
                    DB::raw('AVG(grand_total) as avg_order_value')
                )
                ->groupBy(DB::raw('DATE_FORMAT(created_at, "%Y-%m"), MONTHNAME(created_at), YEAR(created_at)'))
                ->orderBy('month')
                ->get();
                
            // Calculate average order value
            $avgOrderValue = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0;
            
            // Find peak month (highest revenue)
            $peakMonth = $monthlyBreakdown->isEmpty() ? null : $monthlyBreakdown->sortByDesc('revenue')->first();
            
            // Find busiest month (most orders)
            $busiestMonth = $monthlyBreakdown->isEmpty() ? null : $monthlyBreakdown->sortByDesc('order_count')->first();
            
            // Format monthly data for charts
            $formattedMonthlyData = [];
            foreach ($monthlyBreakdown as $month) {
                $formattedMonthlyData[] = [
                    'month' => $month->month_name . ' ' . $month->year,
                    'month_name' => $month->month_name,
                    'year' => $month->year,
                    'date' => $month->month,
                    'order_count' => $month->order_count,
                    'revenue' => $month->revenue,
                    'avg_order_value' => $month->avg_order_value,
                    'is_peak_orders' => $busiestMonth && $month->month === $busiestMonth->month,
                    'is_low_orders' => $month->order_count === $monthlyBreakdown->where('order_count', '>', 0)->min('order_count'),
                    'is_peak_revenue' => $peakMonth && $month->month === $peakMonth->month,
                    'is_low_revenue' => $month->revenue == $monthlyBreakdown->where('revenue', '>', 0)->min('revenue')
                ];
            }
            
            // Get hourly breakdown for all time
            $hourlyBreakdown = DB::table('orders')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->where('status', '!=', 'cancelled')
                ->where('payment_status', 'paid')
                ->select(
                    DB::raw('HOUR(created_at) as hour'),
                    DB::raw('CASE 
                        WHEN HOUR(created_at) = 0 THEN "12 AM"
                        WHEN HOUR(created_at) < 12 THEN CONCAT(HOUR(created_at), " AM")
                        WHEN HOUR(created_at) = 12 THEN "12 PM"
                        ELSE CONCAT(HOUR(created_at) - 12, " PM")
                    END as hour_formatted'),
                    DB::raw('COUNT(*) as order_count'),
                    DB::raw('SUM(grand_total) as revenue'),
                    DB::raw('AVG(grand_total) as avg_order_value')
                )
                ->groupBy(DB::raw('HOUR(created_at)'))
                ->orderBy('hour')
                ->get();
            
            // Find peak hour (highest revenue)
            $peakHour = $hourlyBreakdown->isEmpty() ? null : $hourlyBreakdown->sortByDesc('revenue')->first();
            
            // Find busiest hour (most orders)
            $peakOrderHour = $hourlyBreakdown->isEmpty() ? null : $hourlyBreakdown->sortByDesc('order_count')->first();
            
            // Find lowest revenue hour
            $lowHour = $hourlyBreakdown->where('revenue', '>', 0)->sortBy('revenue')->first();
            
            // Find quietest hour
            $lowOrderHour = $hourlyBreakdown->where('order_count', '>', 0)->sortBy('order_count')->first();
            
            // Format hourly data for charts
            $formattedHourlyData = [];
            foreach ($hourlyBreakdown as $hour) {
                $formattedHourlyData[] = [
                    'hour' => $hour->hour,
                    'hour_formatted' => $hour->hour_formatted,
                    'order_count' => $hour->order_count,
                    'revenue' => $hour->revenue,
                    'avg_order_value' => $hour->avg_order_value,
                    'is_peak_orders' => $peakOrderHour && $hour->hour === $peakOrderHour->hour && $hour->order_count > 0,
                    'is_low_orders' => $lowOrderHour && $hour->hour === $lowOrderHour->hour && $hour->order_count > 0,
                    'is_peak_revenue' => $peakHour && $hour->hour === $peakHour->hour && $hour->revenue > 0,
                    'is_low_revenue' => $lowHour && $hour->hour === $lowHour->hour && $hour->revenue > 0,
                    'time_range' => 'alltime'
                ];
            }
            
            // Create segment analysis data
            // Define time segments
            $segments = [
                'morning' => ['name' => 'Morning', 'start' => 6, 'end' => 11], // 6:00 - 11:59
                'afternoon' => ['name' => 'Afternoon', 'start' => 12, 'end' => 17], // 12:00 - 17:59
                'evening' => ['name' => 'Evening', 'start' => 18, 'end' => 23], // 18:00 - 23:59
                'night' => ['name' => 'Night', 'start' => 0, 'end' => 5], // 0:00 - 5:59
            ];
            
            // Group hourly data into segments
            $segmentData = [];
            foreach ($segments as $key => $segment) {
                $segmentHours = $hourlyBreakdown->filter(function($hour) use ($segment) {
                    return $hour->hour >= $segment['start'] && $hour->hour <= $segment['end'];
                });
                
                $segmentOrderCount = $segmentHours->sum('order_count');
                $segmentRevenue = $segmentHours->sum('revenue');
                
                $segmentData[$key] = [
                    'name' => $segment['name'],
                    'order_count' => $segmentOrderCount,
                    'revenue' => $segmentRevenue,
                    'avg_order_value' => $segmentOrderCount > 0 ? $segmentRevenue / $segmentOrderCount : 0
                ];
            }
            
            // Format segment data for frontend
            $formattedSegments = [
                'morning' => $segmentData['morning'],
                'afternoon' => $segmentData['afternoon'],
                'evening' => $segmentData['evening'],
                'night' => $segmentData['night'],
                'peak_hour' => $peakOrderHour ? [
                    'hour' => $peakOrderHour->hour,
                    'hour_formatted' => $peakOrderHour->hour_formatted,
                    'order_count' => $peakOrderHour->order_count,
                    'revenue' => $peakOrderHour->revenue,
                    'label' => 'Busiest hour of all time'
                ] : null,
                'low_hour' => $lowOrderHour ? [
                    'hour' => $lowOrderHour->hour,
                    'hour_formatted' => $lowOrderHour->hour_formatted,
                    'order_count' => $lowOrderHour->order_count,
                    'revenue' => $lowOrderHour->revenue,
                    'label' => 'Quietest hour of all time'
                ] : null
            ];
            
            // Create metrics for peak hours
            $peakHoursMetrics = [
                'peak_orders_hour' => $peakOrderHour ? $peakOrderHour->hour_formatted : null,
                'peak_revenue_hour' => $peakHour ? $peakHour->hour_formatted : null,
                'low_orders_hour' => $lowOrderHour ? $lowOrderHour->hour_formatted : null,
                'low_revenue_hour' => $lowHour ? $lowHour->hour_formatted : null,
                'time_range' => 'alltime'
            ];
            
            // Create metrics for peak months
            $peakMonthsMetrics = [
                'peak_orders_month' => $busiestMonth ? $busiestMonth->month_name . ' ' . $busiestMonth->year : null,
                'peak_revenue_month' => $peakMonth ? $peakMonth->month_name . ' ' . $peakMonth->year : null,
                'low_orders_month' => $monthlyBreakdown->where('order_count', '>', 0)->sortBy('order_count')->first() ? 
                    $monthlyBreakdown->where('order_count', '>', 0)->sortBy('order_count')->first()->month_name . ' ' . 
                    $monthlyBreakdown->where('order_count', '>', 0)->sortBy('order_count')->first()->year : null,
                'low_revenue_month' => $monthlyBreakdown->where('revenue', '>', 0)->sortBy('revenue')->first() ? 
                    $monthlyBreakdown->where('revenue', '>', 0)->sortBy('revenue')->first()->month_name . ' ' . 
                    $monthlyBreakdown->where('revenue', '>', 0)->sortBy('revenue')->first()->year : null,
                'time_range' => 'alltime'
            ];
            
            // Calculate year-over-year growth if possible
            $currentYear = Carbon::now()->year;
            $lastYear = $currentYear - 1;
            
            // Current year data (with date range filter for consistency)
            $currentYearStart = max($startDate, Carbon::createFromDate($currentYear, 1, 1)->startOfDay());
            $currentYearEnd = min($endDate, Carbon::createFromDate($currentYear, 12, 31)->endOfDay());
            
            $currentYearRevenue = DB::table('orders')
                ->whereBetween('created_at', [$currentYearStart, $currentYearEnd])
                ->where('status', '!=', 'cancelled')
                ->where('payment_status', 'paid')
                ->sum('grand_total');
                
            $currentYearOrders = DB::table('orders')
                ->whereBetween('created_at', [$currentYearStart, $currentYearEnd])
                ->where('status', '!=', 'cancelled')
                ->where('payment_status', 'paid')
                ->count();
                
            // Last year data (with date range filter for consistency)
            $lastYearStart = max($startDate, Carbon::createFromDate($lastYear, 1, 1)->startOfDay());
            $lastYearEnd = min($endDate, Carbon::createFromDate($lastYear, 12, 31)->endOfDay());
            
            $lastYearRevenue = DB::table('orders')
                ->whereBetween('created_at', [$lastYearStart, $lastYearEnd])
                ->where('status', '!=', 'cancelled')
                ->where('payment_status', 'paid')
                ->sum('grand_total');
                
            $lastYearOrders = DB::table('orders')
                ->whereBetween('created_at', [$lastYearStart, $lastYearEnd])
                ->where('status', '!=', 'cancelled')
                ->where('payment_status', 'paid')
                ->count();
            
            // Calculate percentage changes
            $revenueChange = $lastYearRevenue > 0 ? 
                (($currentYearRevenue - $lastYearRevenue) / $lastYearRevenue) * 100 : 
                ($currentYearRevenue > 0 ? 100 : 0);
                
            $ordersChange = $lastYearOrders > 0 ? 
                (($currentYearOrders - $lastYearOrders) / $lastYearOrders) * 100 : 
                ($currentYearOrders > 0 ? 100 : 0);
            
            // Create performance indicator
            $performanceIndicator = [
                'revenue_change_percent' => round($revenueChange, 2),
                'orders_change_percent' => round($ordersChange, 2),
                'previous_period_revenue' => $lastYearRevenue,
                'previous_period_orders' => $lastYearOrders,
                'overall_status' => $revenueChange >= 0 ? 'positive' : 'negative',
                'comparison_label' => 'Year-over-year growth (' . $lastYear . ' vs ' . $currentYear . ')'
            ];
            
            return response()->json([
                'status' => 'success',
                'data' => [
                    'total_revenue' => $totalRevenue,
                    'total_orders' => $totalOrders,
                    'avg_order_value' => $avgOrderValue,
                    'peak_month' => $peakMonth,
                    'busiest_month' => $busiestMonth,
                    'monthly_breakdown' => $monthlyBreakdown,
                    'peak_months_data' => $formattedMonthlyData, // For peak months chart
                    'peak_hours_data' => $formattedHourlyData, // For peak hours chart
                    'segments' => $formattedSegments, // For segment analysis
                    'metrics' => [
                        'hours' => $peakHoursMetrics, // For peak hours metrics
                        'months' => $peakMonthsMetrics // For peak months metrics
                    ],
                    'performance_indicator' => $performanceIndicator, // Business performance indicator
                    'date_range' => [
                        'start' => $startDate->toDateTimeString(),
                        'end' => $endDate->toDateTimeString()
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching all-time summary: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch all-time summary: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get 24-hour summary for dashboard
     */
    public function getTwentyFourHourSummary(Request $request)
    {
        try {
            // Set time period - last 24 hours
            $endDate = Carbon::now();
            $startDate = Carbon::now()->subHours(24);
            
            // Get total revenue and orders for the period
            $totalRevenue = Order::whereBetween('created_at', [$startDate, $endDate])
                ->where('status', '!=', 'cancelled')
                ->where('payment_status', 'paid')
                ->sum('grand_total');
                
            $totalOrders = Order::whereBetween('created_at', [$startDate, $endDate])
                ->where('status', '!=', 'cancelled')
                ->where('payment_status', 'paid')
                ->count();
            
            // Calculate average order value
            $avgOrderValue = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0;
            
            // Get hourly breakdown
            // Temporarily disable strict mode for group by
            DB::statement("SET SESSION sql_mode=''");
            
            $hourlyBreakdown = Order::whereBetween('created_at', [$startDate, $endDate])
                ->where('status', '!=', 'cancelled')
                ->where('payment_status', 'paid')
                ->select(
                    DB::raw('HOUR(created_at) as hour'),
                    DB::raw('COUNT(*) as order_count'),
                    DB::raw('SUM(grand_total) as revenue'),
                    DB::raw('AVG(grand_total) as avg_order_value')
                )
                ->groupBy(DB::raw('HOUR(created_at)'))
                ->orderBy('hour')
                ->get();
            
            // Re-enable strict mode
            DB::statement("SET SESSION sql_mode='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION'");
            
            // Find peak hour (highest revenue)
            $peakHour = $hourlyBreakdown->isEmpty() ? null : $hourlyBreakdown->sortByDesc('revenue')->first();
            
            // Find busiest hour (most orders)
            $busiestHour = $hourlyBreakdown->isEmpty() ? null : $hourlyBreakdown->sortByDesc('order_count')->first();
            
            // Find low hour (lowest revenue with at least one order)
            $lowHour = $hourlyBreakdown->where('order_count', '>', 0)->sortBy('revenue')->first();
            
            // Find quietest hour (fewest orders but at least one)
            $quietestHour = $hourlyBreakdown->where('order_count', '>', 0)->sortBy('order_count')->first();
            
            // Format hourly data for the chart
            $formattedHourlyData = [];
            foreach ($hourlyBreakdown as $hour) {
                $hourFormatted = sprintf('%02d:00', $hour->hour);
                $formattedHourlyData[] = [
                    'hour' => $hour->hour,
                    'hour_formatted' => $hourFormatted,
                    'order_count' => $hour->order_count,
                    'revenue' => $hour->revenue,
                    'avg_order_value' => $hour->avg_order_value,
                    'is_peak_revenue' => $peakHour && $hour->hour == $peakHour->hour,
                    'is_peak_orders' => $busiestHour && $hour->hour == $busiestHour->hour,
                    'is_low_revenue' => $lowHour && $hour->hour == $lowHour->hour,
                    'is_low_orders' => $quietestHour && $hour->hour == $quietestHour->hour
                ];
            }
            
            // Create metrics for peak hours
            $peakHoursMetrics = [
                'peak_orders_hour' => $busiestHour ? sprintf('%02d:00', $busiestHour->hour) : null,
                'peak_revenue_hour' => $peakHour ? sprintf('%02d:00', $peakHour->hour) : null,
                'low_orders_hour' => $quietestHour ? sprintf('%02d:00', $quietestHour->hour) : null,
                'low_revenue_hour' => $lowHour ? sprintf('%02d:00', $lowHour->hour) : null,
                'time_range' => '24hours'
            ];
            
            // Create time segment analysis (morning, afternoon, evening, night)
            $segments = [
                'morning' => ['start' => 6, 'end' => 11, 'name' => 'Morning', 'order_count' => 0, 'revenue' => 0],
                'afternoon' => ['start' => 12, 'end' => 17, 'name' => 'Afternoon', 'order_count' => 0, 'revenue' => 0],
                'evening' => ['start' => 18, 'end' => 21, 'name' => 'Evening', 'order_count' => 0, 'revenue' => 0],
                'night' => ['start' => 22, 'end' => 5, 'name' => 'Night', 'order_count' => 0, 'revenue' => 0]
            ];
            
            // Populate segment data
            foreach ($hourlyBreakdown as $hourData) {
                $hourInt = (int)$hourData->hour;
                
                if ($hourInt >= 6 && $hourInt <= 11) {
                    $segments['morning']['order_count'] += $hourData->order_count;
                    $segments['morning']['revenue'] += $hourData->revenue;
                } else if ($hourInt >= 12 && $hourInt <= 17) {
                    $segments['afternoon']['order_count'] += $hourData->order_count;
                    $segments['afternoon']['revenue'] += $hourData->revenue;
                } else if ($hourInt >= 18 && $hourInt <= 21) {
                    $segments['evening']['order_count'] += $hourData->order_count;
                    $segments['evening']['revenue'] += $hourData->revenue;
                } else {
                    $segments['night']['order_count'] += $hourData->order_count;
                    $segments['night']['revenue'] += $hourData->revenue;
                }
            }
            
            // Calculate performance indicators by comparing to previous period
            $previousPeriodStart = $startDate->copy()->subHours(24);
            $previousPeriodEnd = $endDate->copy()->subHours(24);
            
            // Get previous period data
            $previousPeriodOrders = Order::whereBetween('created_at', [$previousPeriodStart, $previousPeriodEnd])
                ->where('status', '!=', 'cancelled')
                ->where('payment_status', 'paid')
                ->count();
                
            $previousPeriodRevenue = Order::whereBetween('created_at', [$previousPeriodStart, $previousPeriodEnd])
                ->where('status', '!=', 'cancelled')
                ->where('payment_status', 'paid')
                ->sum('grand_total');
            
            // Calculate percentage changes
            $revenueChange = $previousPeriodRevenue > 0 ? 
                (($totalRevenue - $previousPeriodRevenue) / $previousPeriodRevenue) * 100 : 
                ($totalRevenue > 0 ? 100 : 0);
                
            $ordersChange = $previousPeriodOrders > 0 ? 
                (($totalOrders - $previousPeriodOrders) / $previousPeriodOrders) * 100 : 
                ($totalOrders > 0 ? 100 : 0);
            
            // Create performance indicator
            $performanceIndicator = [
                'revenue_change_percent' => round($revenueChange, 2),
                'orders_change_percent' => round($ordersChange, 2),
                'previous_period_revenue' => $previousPeriodRevenue,
                'previous_period_orders' => $previousPeriodOrders,
                'overall_status' => $revenueChange >= 0 ? 'positive' : 'negative'
            ];
            
            return response()->json([
                'status' => 'success',
                'data' => [
                    'total_revenue' => $totalRevenue,
                    'total_orders' => $totalOrders,
                    'avg_order_value' => $avgOrderValue,
                    'hourly_breakdown' => $formattedHourlyData,
                    'metrics' => [
                        'hours' => $peakHoursMetrics
                    ],
                    'segments' => $segments,
                    'performance_indicator' => $performanceIndicator,
                    'date_range' => [
                        'start' => $startDate->toDateTimeString(),
                        'end' => $endDate->toDateTimeString()
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching 24-hour summary: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch 24-hour summary: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getDashboardStats()
    {
        try {
            $now = Carbon::now();
            $thirtyDaysAgo = $now->copy()->subDays(30);

            // Get product status counts
            $productCounts = [
                'low_stock_count' => Product::where('stock_quantity', '>', 0)
                    ->where('stock_quantity', '<=', 10)
                    ->count(),
                    
                'out_of_stock_count' => Product::where('stock_quantity', 0)
                    ->count(),
                    
                'about_to_expire_count' => Product::where('expiry_date', '>', $now)
                    ->where('expiry_date', '<=', $now->copy()->addDays(30))
                    ->count(),
                    
                'expired_count' => Product::where('expiry_date', '<=', $now)
                    ->count()
            ];

            // Get order stats
            $orderStats = [
                'total_sales' => Order::where('status', '!=', 'cancelled')
                    ->where('payment_status', 'paid')
                    ->sum('grand_total'),
                    
                'total_orders' => Order::count(),
                
                'pending_orders' => Order::where('status', 'pending')->count(),
                
                'recent_orders' => Order::with('user')
                    ->orderBy('created_at', 'desc')
                    ->take(5)
                    ->get()
                    ->map(function($order) {
                        return [
                            'id' => $order->id,
                            'order_number' => $order->order_number,
                            'customer_name' => $order->user ? $order->user->name : 'Guest',
                            'total' => $order->grand_total,
                            'status' => $order->status,
                            'payment_status' => $order->payment_status,
                            'created_at' => $order->created_at
                        ];
                    }),
                
                'orders_by_status' => Order::select('status', DB::raw('count(*) as count'))
                    ->groupBy('status')
                    ->get()
            ];

            // Get customer stats
            $customerStats = [
                'total_customers' => User::count(),
                'new_customers' => User::where('created_at', '>=', $thirtyDaysAgo)->count()
            ];

            return response()->json([
                'status' => 'success',
                'data' => array_merge(
                    $productCounts,
                    $orderStats,
                    $customerStats,
                    ['date_range' => [
                        'start_date' => $thirtyDaysAgo->format('Y-m-d'),
                        'end_date' => $now->format('Y-m-d')
                    ]]
                )
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching dashboard stats: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch dashboard stats: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Import products from CSV/Excel file.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function importProducts(Request $request)
    {
        try {
            // Validate the uploaded file
            $validator = Validator::make($request->all(), [
                'file' => 'required|file|mimes:csv,txt|max:10240', // Max 10MB
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid file',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Import the products
            $import = new \App\Imports\ProductsImport();
            $import->import($request->file('file'));

            // Get import statistics
            $stats = $import->getStats();

            // Get failures
            $failures = $import->failures();
            $failureMessages = [];

            foreach ($failures as $failure) {
                $failureMessages[] = [
                    'row' => $failure['row'],
                    'attribute' => $failure['attribute'],
                    'errors' => $failure['errors'],
                    'values' => $failure['values'] ?? [],
                ];
            }

            // Clear product cache
            $this->clearProductCache();

            return response()->json([
                'status' => 'success',
                'message' => 'Products imported successfully',
                'data' => [
                    'stats' => $stats,
                    'failures' => $failureMessages
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('Error importing products: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to import products: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Download product import template.
     *
     * @return \Illuminate\Http\Response
     */
    public function downloadImportTemplate()
    {
        try {
            // Create a template file if it doesn't exist
            $templatePath = storage_path('app/templates');
            if (!file_exists($templatePath)) {
                mkdir($templatePath, 0755, true);
            }
            
            $templateFile = $templatePath . '/products_import_template.csv';
            
            // Create the template file with headers
            $headers = [
                'name', 'sku', 'base_price', 'sale_price', 'stock_quantity', 
                'category', 'description', 'short_description', 'is_active', 
                'is_featured', 'is_new_arrival', 'is_hot_deal', 'is_best_seller', 
                'is_expiring_soon', 'is_clearance', 'is_recommended', 'expiry_date', 
                'weight', 'dimensions', 'meta_title', 'meta_description', 'meta_keywords',
                'image_url', 'brand', 'barcode'
            ];
            
            $handle = fopen($templateFile, 'w');
            fputcsv($handle, $headers);
            
            // Add sample products
            $sampleProducts = [
                [
                    'Organic Banana Bunch', 'FRUIT001', '2.99', '2.49', '50',
                    'Fresh Foods', 'Fresh organic banana bunch from local farms', 'Organic bananas',
                    '1', '1', '1', '0', '0', '0', '0', '1', '2023-12-31',
                    '0.5', '20x10x5', 'Organic Bananas', 'Fresh organic bananas from local farms', 'banana,organic,fruit',
                    'https://placehold.co/600x400?font=roboto&text=Banana', 'Organic Farms', 'BANA123456'
                ],
                [
                    'Premium Coffee Beans', 'COFFEE001', '14.99', '12.99', '30',
                    'Updated Beverages Name', 'Premium Arabica coffee beans, dark roast', 'Premium coffee',
                    '1', '1', '0', '1', '1', '0', '0', '1', '2024-06-30',
                    '0.35', '15x8x22', 'Premium Coffee Beans', 'Arabica coffee beans, dark roast', 'coffee,beans,arabica,dark roast',
                    'https://placehold.co/600x400?font=roboto&text=Coffee', 'Coffee Delight', 'COFF789012'
                ],
                [
                    'Smart LED TV 55"', 'ELEC001', '499.99', '449.99', '15',
                    'Electronics', '55-inch Smart LED TV with 4K resolution', 'Smart LED TV',
                    '1', '0', '1', '0', '0', '0', '0', '1', '2024-12-31',
                    '15.2', '123x71x8', 'Smart LED TV 55"', '55-inch Smart LED TV with 4K resolution', 'tv,smart,led,4k,electronics',
                    'https://placehold.co/600x400?font=roboto&text=TV', 'TechVision', 'ELTV567890'
                ],
                // New Category 1: Home & Garden
                [
                    'Luxury Memory Foam Pillow', 'HOME001', '39.99', '34.99', '25',
                    'Home & Garden', 'Premium memory foam pillow for ultimate comfort and support', 'Memory foam pillow',
                    '1', '1', '0', '1', '0', '0', '0', '1', '2025-12-31',
                    '1.2', '60x40x15', 'Luxury Memory Foam Pillow', 'Premium memory foam pillow for ultimate comfort', 'pillow,memory foam,bedding,sleep',
                    'https://placehold.co/600x400?font=roboto&text=Pillow', 'DreamSleep', 'HMPL123456'
                ],
                [
                    'Indoor Plant Collection', 'HOME002', '49.99', '44.99', '15',
                    'Home & Garden', 'Set of 3 easy-care indoor plants in decorative pots', 'Indoor plant set',
                    '1', '0', '1', '0', '1', '0', '0', '1', '2024-08-15',
                    '2.5', '30x30x40', 'Indoor Plant Collection', 'Set of 3 easy-care indoor plants', 'plants,indoor,home decor,gardening',
                    'https://placehold.co/600x400?font=roboto&text=Plants', 'GreenThumb', 'HMPL234567'
                ],
                [
                    'Stainless Steel Garden Tools Set', 'HOME003', '29.99', '24.99', '20',
                    'Home & Garden', 'Complete set of 5 stainless steel garden tools with ergonomic handles', 'Garden tools set',
                    '1', '0', '0', '0', '1', '0', '0', '1', '2025-03-31',
                    '1.8', '35x25x10', 'Garden Tools Set', 'Complete set of stainless steel garden tools', 'garden,tools,stainless steel,outdoor',
                    'https://placehold.co/600x400?font=roboto&text=Garden+Tools', 'GardenPro', 'HMTL345678'
                ],
                // New Category 2: Sports & Fitness
                [
                    'Professional Yoga Mat', 'SPORT001', '45.99', '39.99', '30',
                    'Sports & Fitness', 'Non-slip professional yoga mat with carrying strap, 6mm thickness', 'Professional yoga mat',
                    '1', '1', '1', '0', '0', '0', '0', '1', '2025-06-30',
                    '1.5', '180x60x0.6', 'Professional Yoga Mat', 'Non-slip yoga mat with carrying strap', 'yoga,mat,fitness,exercise',
                    'https://placehold.co/600x400?font=roboto&text=Yoga+Mat', 'FitLife', 'SPYM456789'
                ],
                [
                    'Smart Fitness Tracker', 'SPORT002', '89.99', '79.99', '25',
                    'Sports & Fitness', 'Waterproof fitness tracker with heart rate monitor and sleep tracking', 'Smart fitness tracker',
                    '1', '1', '1', '1', '0', '0', '0', '1', '2025-05-15',
                    '0.05', '22x1.5x1', 'Smart Fitness Tracker', 'Waterproof fitness tracker with heart rate monitor', 'fitness,tracker,smart,wearable',
                    'https://placehold.co/600x400?font=roboto&text=Fitness+Tracker', 'TechFit', 'SPFT567890'
                ],
                [
                    'Adjustable Dumbbell Set', 'SPORT003', '129.99', '119.99', '10',
                    'Sports & Fitness', 'Adjustable dumbbell set with weights from 5-25kg each', 'Adjustable dumbbells',
                    '1', '0', '0', '1', '1', '0', '0', '1', '2025-12-31',
                    '25.0', '40x20x20', 'Adjustable Dumbbell Set', 'Adjustable dumbbells with weights from 5-25kg', 'dumbbells,weights,fitness,strength training',
                    'https://placehold.co/600x400?font=roboto&text=Dumbbells', 'PowerFit', 'SPDB678901'
                ],
                // New Category 3: Beauty & Personal Care
                [
                    'Organic Skincare Gift Set', 'BEAUTY001', '59.99', '49.99', '20',
                    'Beauty & Personal Care', 'Complete organic skincare set with cleanser, toner, moisturizer, and serum', 'Organic skincare set',
                    '1', '1', '0', '1', '0', '0', '0', '1', '2024-12-15',
                    '0.8', '25x20x10', 'Organic Skincare Gift Set', 'Complete organic skincare set', 'skincare,organic,beauty,gift set',
                    'https://placehold.co/600x400?font=roboto&text=Skincare+Set', 'NaturalGlow', 'BTSK789012'
                ],
                [
                    'Professional Hair Dryer', 'BEAUTY002', '79.99', '69.99', '15',
                    'Beauty & Personal Care', '2000W professional hair dryer with ionic technology and diffuser', 'Professional hair dryer',
                    '1', '0', '1', '0', '1', '0', '0', '1', '2025-06-30',
                    '0.7', '30x10x20', 'Professional Hair Dryer', '2000W hair dryer with ionic technology', 'hair dryer,professional,beauty,hair care',
                    'https://placehold.co/600x400?font=roboto&text=Hair+Dryer', 'StylePro', 'BTHD890123'
                ],
                [
                    'Luxury Shaving Kit', 'BEAUTY003', '69.99', '59.99', '12',
                    'Beauty & Personal Care', 'Premium shaving kit with razor, brush, stand, and shaving cream', 'Luxury shaving kit',
                    '1', '0', '0', '0', '0', '1', '1', '1', '2025-09-30',
                    '0.6', '20x15x10', 'Luxury Shaving Kit', 'Premium shaving kit with razor and accessories', 'shaving,men,grooming,luxury',
                    'https://placehold.co/600x400?font=roboto&text=Shaving+Kit', 'GentlemanCare', 'BTSK901234'
                ]
            ];
            
            foreach ($sampleProducts as $product) {
                fputcsv($handle, $product);
            }
            
            fclose($handle);
            
            return response()->download($templateFile, 'products_import_template.csv', [
                'Content-Type' => 'text/csv',
            ]);
        } catch (\Exception $e) {
            \Log::error('Error creating template: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate template: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check if stock is low and send alert if needed
     *
     * @param Product $product
     */
    private function checkAndSendLowStockAlert(Product $product)
    {
        try {
            // Get low stock threshold from settings or use default value
            $threshold = (int)Setting::getValue('low_stock_threshold', 10);
            
            // Check if product stock is below threshold
            if ($product->stock_quantity <= $threshold) {
                Log::info("Low stock detected for product #{$product->id}: {$product->name}", [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'current_stock' => $product->stock_quantity,
                    'threshold' => $threshold
                ]);
                
                // Send email notification
                NotificationService::sendLowStockAlert($product, $threshold);
                
                // Send SMS notification
                NotificationService::sendLowStockAlertSms($product, $threshold);
                
                return true;
            }
            
            return false;
        } catch (\Exception $e) {
            Log::error("Error checking low stock for product #{$product->id}: " . $e->getMessage());
            return false;
        }
    }
}
