<?php

namespace App\Imports;

use App\Models\Product;
use App\Models\Category;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ProductsImport
{
    protected $createdCount = 0;
    protected $updatedCount = 0;
    protected $skippedCount = 0;
    protected $failureCount = 0;
    protected $failures = [];

    /**
     * Import products from a file.
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @return void
     */
    public function import($file)
    {
        try {
            // Get file extension
            $extension = $file->getClientOriginalExtension();
            if ($extension == 'csv') {
                return $this->importCSV($file);
            } else {
                throw new \Exception("Only CSV files are supported at this time");
            }
        } catch (\Exception $e) {
            Log::error('Error importing products: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Import products from a CSV file.
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @return void
     */
    protected function importCSV($file)
    {
        // Open the file
        $handle = fopen($file->getPathname(), 'r');
        
        // Get the header row
        $header = fgetcsv($handle);
        
        // Convert header to lowercase for case-insensitive matching
        $header = array_map('strtolower', $header);
        
        // Process each row
        $row = 1; // Start from row 1 (header is row 0)
        while (($data = fgetcsv($handle)) !== false) {
            $row++;
            
            try {
                // Skip empty rows
                if (count(array_filter($data)) === 0) {
                    $this->skippedCount++;
                    continue;
                }
                
                // Combine header with data to create associative array
                $data = array_combine($header, $data);
                
                // Validate the data
                $validator = Validator::make($data, [
                    'name' => 'required|string|max:255',
                    'sku' => 'required|string|max:100',
                    'base_price' => 'required|numeric|min:0',
                    'stock_quantity' => 'required|integer|min:0',
                ]);
                
                if ($validator->fails()) {
                    foreach ($validator->errors()->messages() as $attribute => $messages) {
                        $this->failures[] = [
                            'row' => $row,
                            'attribute' => $attribute,
                            'errors' => $messages,
                            'values' => $data
                        ];
                    }
                    $this->failureCount++;
                    continue;
                }
                
                // Get or create the category
                $categoryId = $this->getCategoryId($data['category'] ?? null);
                
                // Check if product with this SKU already exists
                $product = Product::where('sku', $data['sku'])->first();
                
                // Prepare product data
                $productData = [
                    'name' => $data['name'],
                    'slug' => Str::slug($data['name']),
                    'description' => $data['description'] ?? null,
                    'short_description' => $data['short_description'] ?? null,
                    'sku' => $data['sku'],
                    'base_price' => $data['base_price'] ?? 0,
                    'sale_price' => $data['sale_price'] ?? null,
                    'stock_quantity' => $data['stock_quantity'] ?? 0,
                    'category_id' => $categoryId,
                    'is_active' => $this->parseBoolean($data['is_active'] ?? true),
                    'is_featured' => $this->parseBoolean($data['is_featured'] ?? false),
                    'is_new_arrival' => $this->parseBoolean($data['is_new_arrival'] ?? false),
                    'is_hot_deal' => $this->parseBoolean($data['is_hot_deal'] ?? false),
                    'is_best_seller' => $this->parseBoolean($data['is_best_seller'] ?? false),
                    'is_expiring_soon' => $this->parseBoolean($data['is_expiring_soon'] ?? false),
                    'is_clearance' => $this->parseBoolean($data['is_clearance'] ?? false),
                    'is_recommended' => $this->parseBoolean($data['is_recommended'] ?? false),
                    'expiry_date' => $data['expiry_date'] ?? null,
                    'weight' => $data['weight'] ?? null,
                    'dimensions' => $data['dimensions'] ?? null,
                    'meta_title' => $data['meta_title'] ?? null,
                    'meta_description' => $data['meta_description'] ?? null,
                    'meta_keywords' => $data['meta_keywords'] ?? null,
                    'image_url' => $data['image_url'] ?? null,
                    'brand' => $data['brand'] ?? null,
                    'barcode' => $data['barcode'] ?? null,
                ];
                
                if ($product) {
                    // Update existing product
                    $product->update($productData);
                    $this->updatedCount++;
                } else {
                    // Create new product
                    Product::create($productData);
                    $this->createdCount++;
                }
            } catch (\Exception $e) {
                Log::error('Error importing row ' . $row . ': ' . $e->getMessage());
                $this->failures[] = [
                    'row' => $row,
                    'attribute' => 'general',
                    'errors' => [$e->getMessage()],
                    'values' => $data ?? []
                ];
                $this->failureCount++;
            }
        }
        
        fclose($handle);
    }

    /**
     * Get or create a category by name.
     *
     * @param string|null $categoryName
     * @return int|null
     */
    protected function getCategoryId($categoryName)
    {
        if (!$categoryName) {
            return null;
        }
        
        $category = Category::firstOrCreate(
            ['name' => $categoryName],
            [
                'slug' => Str::slug($categoryName),
                'description' => 'Auto-created during product import',
                'is_active' => true
            ]
        );
        
        return $category->id;
    }

    /**
     * Parse boolean values from various formats.
     *
     * @param mixed $value
     * @return bool
     */
    protected function parseBoolean($value)
    {
        if (is_bool($value)) {
            return $value;
        }
        
        if (is_string($value)) {
            $value = strtolower(trim($value));
            return in_array($value, ['true', 'yes', 'y', '1', 'on']);
        }
        
        return (bool) $value;
    }

    /**
     * Get import statistics.
     *
     * @return array
     */
    public function getStats()
    {
        return [
            'imported' => $this->createdCount,
            'updated' => $this->updatedCount,
            'skipped' => $this->skippedCount,
            'failures' => $this->failureCount,
            'total' => $this->createdCount + $this->updatedCount + $this->skippedCount + $this->failureCount
        ];
    }

    /**
     * Get import failures.
     *
     * @return array
     */
    public function failures()
    {
        return $this->failures;
    }
}
