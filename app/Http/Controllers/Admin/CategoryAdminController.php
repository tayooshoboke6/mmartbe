<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class CategoryAdminController extends Controller
{
    /**
     * Display a listing of the categories.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $query = Category::with('subcategories', 'parent')
                ->withCount('products as product_count');

        // Apply filters
        if ($request->has('parent_id')) {
            $query->where('parent_id', $request->parent_id);
        }

        if ($request->has('search')) {
            $query->where('name', 'like', '%' . $request->search . '%')
                ->orWhere('description', 'like', '%' . $request->search . '%');
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Order by
        $orderBy = $request->input('order_by', 'name');
        $orderDir = $request->input('order_dir', 'asc');
        $query->orderBy($orderBy, $orderDir);

        $categories = $query->paginate($request->input('per_page', 15));

        return response()->json([
            'categories' => $categories,
            'parent_categories' => Category::whereNull('parent_id')->orderBy('name')->get(['id', 'name']),
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

        // Clear category cache
        $this->clearCategoryCache();

        return response()->json([
            'message' => 'Category created successfully',
            'category' => $category->load('parent', 'subcategories'),
        ], 201);
    }

    /**
     * Display the specified category.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $category = Category::with('parent', 'subcategories')
            ->withCount('products as product_count')
            ->findOrFail($id);

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
            'name' => 'sometimes|required|string|max:255',
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

        // Prevent circular references
        if ($request->has('parent_id') && $request->parent_id) {
            $parentId = $request->parent_id;
            $potentialParent = Category::find($parentId);
            
            while ($potentialParent && $potentialParent->parent_id) {
                if ($potentialParent->parent_id == $category->id) {
                    return response()->json([
                        'message' => 'Cannot create circular reference in category hierarchy',
                    ], 422);
                }
                $potentialParent = $potentialParent->parent;
            }
        }

        // Handle image upload if using file upload
        if ($request->hasFile('image')) {
            // Delete old image if exists
            if ($category->image_url) {
                Storage::disk('public')->delete($category->image_url);
            }
            
            $imagePath = $request->file('image')->store('categories', 'public');
            $request->merge(['image_url' => $imagePath]);
        }

        $category->update($request->only([
            'name', 'slug', 'description', 'parent_id', 'image_url', 'is_active', 'is_featured', 'color',
        ]));

        // Clear category cache
        $this->clearCategoryCache();

        return response()->json([
            'message' => 'Category updated successfully',
            'category' => $category->fresh(['parent', 'subcategories']),
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
                'message' => 'Cannot delete category with subcategories. Please delete or reassign subcategories first.',
            ], 422);
        }

        // Check if category has products
        if ($category->products()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete category with products. Please delete or reassign products first.',
            ], 422);
        }

        // Delete image if exists
        if ($category->image_url) {
            Storage::disk('public')->delete($category->image_url);
        }

        $category->delete();

        // Clear category cache
        $this->clearCategoryCache();

        return response()->json([
            'message' => 'Category deleted successfully',
        ]);
    }

    /**
     * Get the category tree structure.
     *
     * @return \Illuminate\Http\Response
     */
    public function tree()
    {
        $categories = Category::whereNull('parent_id')
            ->with(['subcategories' => function ($query) {
                $query->orderBy('name', 'asc');
            }])
            ->orderBy('name', 'asc')
            ->get();

        return response()->json([
            'categories' => $categories,
        ]);
    }

    /**
     * Get stock data for all categories for the admin dashboard.
     *
     * @return \Illuminate\Http\Response
     */
    public function getCategoryStockData()
    {
        try {
            // Get all categories
            $categories = Category::select('id', 'name', 'parent_id')->get();
            
            // Get stock data for each category
            $categoriesWithStock = [];
            
            foreach ($categories as $category) {
                // Count products directly related to this category
                $productCount = \DB::table('products')
                    ->where('category_id', $category->id)
                    ->count();
                
                // Sum stock quantity for products in this category
                $stockQuantity = \DB::table('products')
                    ->where('category_id', $category->id)
                    ->sum('stock_quantity') ?? 0;
                
                $categoriesWithStock[] = [
                    'id' => $category->id,
                    'name' => $category->name,
                    'parent_id' => $category->parent_id,
                    'product_count' => $productCount,
                    'stock' => (int) $stockQuantity,
                ];
            }
            
            // Aggregate subcategory counts to parent categories
            $result = $categoriesWithStock;
            $parentCategories = [];
            
            foreach ($categoriesWithStock as $category) {
                if ($category['parent_id']) {
                    foreach ($result as &$parentCategory) {
                        if ($parentCategory['id'] == $category['parent_id']) {
                            $parentCategory['product_count'] += $category['product_count'];
                            $parentCategory['stock'] += $category['stock'];
                            break;
                        }
                    }
                }
            }
            
            // Calculate total stock
            $totalStock = array_sum(array_column($categoriesWithStock, 'stock'));
            
            return response()->json([
                'categories' => $categoriesWithStock,
                'totalStock' => $totalStock
            ]);
        } catch (\Exception $e) {
            \Log::error('Error in getCategoryStockData: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to fetch category stock data',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reorder categories.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function reorder(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'categories' => 'required|array',
            'categories.*.id' => 'required|exists:categories,id',
            'categories.*.order' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        foreach ($request->categories as $categoryData) {
            Category::where('id', $categoryData['id'])->update(['order' => $categoryData['order']]);
        }

        return response()->json([
            'message' => 'Categories reordered successfully',
        ]);
    }

    /**
     * Clear the category cache.
     *
     * @return void
     */
    public function clearCategoryCache()
    {
        // Clear cache for all keys with the categories_ prefix
        $keys = Cache::get('category_cache_keys', []);
        foreach ($keys as $key) {
            Cache::forget($key);
        }
        Cache::forget('category_cache_keys');
        
        // Log cache clearing
        \Illuminate\Support\Facades\Log::info('Category cache cleared');
    }
}
