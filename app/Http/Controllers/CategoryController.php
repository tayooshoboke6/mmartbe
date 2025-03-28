<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    /**
     * Display a listing of the categories.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $query = Category::with('subcategories')
    ->withCount('products as product_count')
    ->withCount('subcategories as subcategory_count')
    ->when($request->boolean('parents_only', false), function ($q) {
        return $q->whereNull('parent_id');
    });

        // Default to active categories for non-admin users
        if (!$request->has('include_inactive') || !$request->user() || !$request->user()->hasRole('admin')) {
            $query->where('is_active', true);
        }

        $categories = $query->orderBy('order')->orderBy('name')->get();

        return response()->json([
            'categories' => $categories
        ]);
    }

    /**
     * Store a newly created category in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'parent_id' => 'nullable|exists:categories,id',
            'image_url' => 'nullable|string',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'color' => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Generate slug from name
        $slug = Str::slug($request->name);
        $originalSlug = $slug;
        $count = 1;

        // Ensure slug is unique
        while (Category::where('slug', $slug)->exists()) {
            $slug = $originalSlug . '-' . $count++;
        }

        $category = Category::create([
            'name' => $request->name,
            'slug' => $slug,
            'description' => $request->description,
            'parent_id' => $request->parent_id,
            'image_url' => $request->image_url,
            'is_active' => $request->boolean('is_active', true),
            'is_featured' => $request->boolean('is_featured', false),
            'color' => $request->input('color', '#000000'),
        ]);

        return response()->json([
            'message' => 'Category created successfully',
            'category' => $category,
        ], 201);
    }

    /**
     * Display the specified category.
     *
     * @param  string  $slug
     * @return \Illuminate\Http\Response
     */
    public function show($slug)
    {
        $category = Category::with('subcategories')
            ->withCount('products as product_count')
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        return response()->json($category);
    }

    /**
     * Update the specified category in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $category = Category::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'parent_id' => 'nullable|exists:categories,id',
            'image_url' => 'nullable|string',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'color' => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Update slug if name changes
        if ($request->has('name') && $request->name !== $category->name) {
            $slug = Str::slug($request->name);
            $originalSlug = $slug;
            $count = 1;

            // Ensure slug is unique
            while (Category::where('slug', $slug)->where('id', '!=', $category->id)->exists()) {
                $slug = $originalSlug . '-' . $count++;
            }

            $request->merge(['slug' => $slug]);
        }

        // Prevent category from being its own parent
        if ($request->has('parent_id') && $request->parent_id == $category->id) {
            return response()->json([
                'message' => 'Category cannot be its own parent',
            ], 422);
        }

        $category->update($request->only([
            'name', 'slug', 'description', 'parent_id', 'image_url', 'is_active', 'is_featured', 'color',
        ]));

        return response()->json([
            'message' => 'Category updated successfully',
            'category' => $category->fresh('subcategories'),
        ]);
    }

    /**
     * Remove the specified category from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $category = Category::findOrFail($id);

        // Check if category has subcategories
        if ($category->subcategories()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete category with subcategories',
            ], 422);
        }

        // Check if category has products
        if ($category->products()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete category with products',
            ], 422);
        }

        $category->delete();

        return response()->json([
            'message' => 'Category deleted successfully',
        ]);
    }

    /**
     * Get products for a specific category.
     *
     * @param  string  $id
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function products($id, Request $request)
    {
        // Allow lookup by ID or slug
        $category = is_numeric($id) 
            ? Category::findOrFail($id)
            : Category::where('slug', $id)->firstOrFail();

        $query = $category->products()
            ->with(['category', 'measurements', 'images']) 
            ->when($request->has('search'), function ($q) use ($request) {
                return $q->where('name', 'like', '%' . $request->search . '%')
                    ->orWhere('description', 'like', '%' . $request->search . '%');
            })
            ->when($request->has('min_price'), function ($q) use ($request) {
                return $q->where('base_price', '>=', $request->min_price);
            })
            ->when($request->has('max_price'), function ($q) use ($request) {
                return $q->where('base_price', '<=', $request->max_price);
            });

        // Default to active products for non-admin users
        if (!$request->has('include_inactive') || !$request->user() || !$request->user()->hasRole('admin')) {
            $query->where('is_active', true);
        }

        // Get paginated results
        $products = $query->paginate($request->input('per_page', 15));

        // Log the response for debugging
        \Log::info('Category products response for category ID: ' . $id, [
            'product_count' => $products->count(),
            'first_product' => $products->count() > 0 ? $products->first()->toArray() : null
        ]);

        // Format the response to match the product detail endpoint format
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
    }

    /**
     * Get categories in a hierarchical tree structure.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function tree(Request $request)
    {
        $includeInactive = $request->has('include_inactive') && $request->user() && $request->user()->hasRole('admin');
        
        // Get the latest category update timestamp to use in the cache key
        $latestUpdate = Category::max('updated_at');
        $cacheKey = 'categories.tree.' . ($includeInactive ? 'with_inactive' : 'active_only') . '.timestamp.' . $latestUpdate;
        
        
        $categoriesData = Cache::remember($cacheKey, 15, function () use ($includeInactive) {
          
            $categories = Category::with(['subcategories' => function($query) use ($includeInactive) {
               
                if (!$includeInactive) {
                    $query->where('is_active', true);
                }
                $query->orderBy('name');
            }])
            ->whereNull('parent_id')
            ->when(!$includeInactive, function ($query) {
                return $query->where('is_active', true);
            })
            ->orderBy('name')
            ->get();

            
            $categoriesTree = $this->buildOptimizedCategoryTree($categories);
            
           
            return [
                'success' => true,
                'categories' => $categoriesTree,
                'timestamp' => now()->toIso8601String(),
                'total_count' => count($categoriesTree),
                'cached' => true,
                'cache_expires_in' => '15 minutes'
            ];
        });
        
       
        return response()->json($categoriesData);
    }           

    /**
     * Build an optimized category tree using eager loaded data.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $categories
     * @return array
     */
    private function buildOptimizedCategoryTree($categories)
    {
        $result = [];

        foreach ($categories as $category) {
            // Build category data
            $categoryData = [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
                'image' => $category->image,
                'is_active' => $category->is_active,
                'children' => []
            ];

            // Add subcategories if they exist
            if ($category->subcategories && $category->subcategories->count() > 0) {
                $categoryData['children'] = $this->buildOptimizedCategoryTree($category->subcategories);
                $categoryData['has_children'] = true;
            } else {
                $categoryData['has_children'] = false;
            }

            $result[] = $categoryData;
        }

        return $result;
    }

    /**
     * Recursively build a category tree with all descendants.
     * 
     * @deprecated Use buildOptimizedCategoryTree instead
     * @param  \Illuminate\Database\Eloquent\Collection  $categories
     * @return array
     */
    private function buildCategoryTree($categories)
    {
        $result = [];

        foreach ($categories as $category) {
            // Get all subcategories
            $subcategories = Category::where('parent_id', $category->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get();

            // Build category data with its children
            $categoryData = [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
                'image' => $category->image,
                'is_active' => $category->is_active,
                'children' => []
            ];

            // Recursively add subcategories if they exist
            if ($subcategories->count() > 0) {
                $categoryData['children'] = $this->buildCategoryTree($subcategories);
            }

            $result[] = $categoryData;
        }

        return $result;
    }
}
