# Category Image Field Renaming Documentation

## Issue Summary

The M-Mart+ e-commerce platform had a mismatch between the frontend and backend regarding how category images were referenced:

- **Frontend**: Expected category images to be accessed via `image_url` property
- **Backend**: Stored category images in an `image` field in the database and controllers

This inconsistency caused category images to not display properly in the frontend of our Nigerian e-commerce platform.

## Solution Steps

### 1. Database Migration

Created and ran a migration to rename the column in the database:

```php
// Migration file: 2025_03_23_164818_rename_image_to_image_url_in_categories_table.php
public function up(): void
{
    Schema::table('categories', function (Blueprint $table) {
        $table->renameColumn('image', 'image_url');
    });
}

public function down(): void
{
    Schema::table('categories', function (Blueprint $table) {
        $table->renameColumn('image_url', 'image');
    });
}
```

### 2. Model Update

Updated the Category model's fillable array to use `image_url` instead of `image`:

```php
// app/Models/Category.php
protected $fillable = [
    'name',
    'slug',
    'description',
    'parent_id',
    'image_url', // Changed from 'image'
    'is_active',
    'order',
    'is_featured',
    'color',
];
```

### 3. Controller Updates

#### Public CategoryController

Updated the validation rules and data handling in the CategoryController:

```php
// app/Http/Controllers/CategoryController.php

// In store method
$validator = Validator::make($request->all(), [
    // ...
    'image_url' => 'nullable|string', // Changed from 'image'
    // ...
]);

// In create method
Category::create([
    // ...
    'image_url' => $request->image_url, // Changed from 'image' => $request->image
    // ...
]);

// In update method
$category->update($request->only([
    'name', 'slug', 'description', 'parent_id', 'image_url', 'is_active', 'is_featured', 'color',
]));
```

#### Admin CategoryAdminController

Updated the admin controller which handles the `/admin/categories` endpoints:

```php
// app/Http/Controllers/Admin/CategoryAdminController.php

// In validation rules
$validator = Validator::make($request->all(), [
    // ...
    'image_url' => 'nullable|string', // Changed from 'image' => 'nullable|image|max:2048'
    // ...
]);

// In store method
Category::create([
    // ...
    'image_url' => $request->image_url, // Changed from 'image' => $imagePath
    // ...
]);

// In update method
if ($request->hasFile('image')) {
    // Delete old image if exists
    if ($category->image_url) { // Changed from $category->image
        Storage::disk('public')->delete($category->image_url);
    }
    
    $imagePath = $request->file('image')->store('categories', 'public');
    $request->merge(['image_url' => $imagePath]); // Changed from 'image' => $imagePath
}

$category->update($request->only([
    'name', 'slug', 'description', 'parent_id', 'image_url', 'is_active', 'is_featured', 'color',
]));
```

### 4. Testing

After making these changes, we tested the API by:

1. Sending a PUT request to `/admin/categories/2` with a JSON payload containing an `image_url` field
2. Verifying that the response showed the updated `image_url` value
3. Checking that the frontend properly displayed the category images

## Key Learnings

1. **Consistent Naming**: Maintain consistent field naming between frontend and backend
2. **Multiple Controllers**: Be aware that different routes may use different controllers for the same model
3. **Complete Updates**: When renaming fields, update all references in:
   - Database (migrations)
   - Models (fillable arrays)
   - Controllers (validation, create/update methods)
   - Frontend code (API requests and rendering)

## Impact

These changes ensure that category images are properly displayed in the M-Mart+ e-commerce platform, enhancing the shopping experience for Nigerian customers browsing products priced in Naira (₦).
