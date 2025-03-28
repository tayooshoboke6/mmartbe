# Category Product Count Fix

## Issue Summary

In the M-Mart+ e-commerce platform, we identified an issue where category cards on the homepage were displaying product counts for mock categories but not for real categories fetched from the backend API. This inconsistency affected the user experience by not showing how many products were available in each category.

## Problem Analysis

1. The frontend code in `Home.js` expected each category to have a `product_count` property:
   ```jsx
   <p className="text-white text-sm opacity-80">{category.product_count} Products</p>
   ```

2. Mock categories included this property:
   ```jsx
   {
     id: 1,
     name: 'Groceries',
     slug: 'groceries',
     image_url: 'https://placehold.co/600x400?font=roboto&text=Groceries',
     product_count: 120
   }
   ```

3. However, the backend API responses for categories did not include product counts.

## Solution Implemented

We updated both the public and admin category controllers to include product counts in their responses:

### 1. Public CategoryController

Updated the `index` and `show` methods to include product counts:

```php
// In index method
$query = Category::with('subcategories')
    ->withCount('products as product_count')
    ->when($request->boolean('parents_only', false), function ($q) {
        return $q->whereNull('parent_id');
    });

// In show method
$category = Category::with('subcategories')
    ->withCount('products as product_count')
    ->where('slug', $slug)
    ->where('is_active', true)
    ->firstOrFail();
```

### 2. Admin CategoryAdminController

Updated the `index` and `show` methods to include product counts:

```php
// In index method
$query = Category::with('subcategories', 'parent')
        ->withCount('products as product_count');

// In show method
$category = Category::with('parent', 'subcategories')
    ->withCount('products as product_count')
    ->findOrFail($id);
```

## Technical Details

1. We used Laravel's `withCount()` method, which adds a `{relation}_count` attribute to the model.
2. We specifically named it `product_count` (instead of the default `products_count`) to match the frontend's expected property name.
3. The Category model already had the necessary relationship defined:
   ```php
   public function products(): HasMany
   {
       return $this->hasMany(Product::class);
   }
   ```

## Expected Results

After these changes:

1. All category API responses now include a `product_count` property.
2. The frontend displays the actual number of products in each category.
3. This creates consistency between mock and real data.
4. Users can now see how many products are available in each category before clicking on it.

## Benefits for M-Mart+

1. **Enhanced User Experience**: Customers can now see the number of products in each category, helping them make informed decisions about which categories to explore.
2. **Consistency**: All categories now display product counts, creating a consistent interface.
3. **Better Information**: For the Nigerian e-commerce platform, this provides more transparency about product availability across different categories.

This change aligns with M-Mart+'s goal of providing a clear, informative shopping experience for customers browsing products priced in Naira (₦).
