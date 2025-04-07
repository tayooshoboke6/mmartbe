<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Category;
use Carbon\Carbon;
use Illuminate\Support\Str;

class YearlyOrderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get all customer users
        $users = User::where('role', 'customer')->get();
        
        // Get all products grouped by category
        $productsByCategory = [];
        $categories = Category::all();
        
        foreach ($categories as $category) {
            $productsByCategory[$category->id] = Product::where('category_id', $category->id)->get();
        }
        
        // Define the start date (1 year ago)
        $startDate = Carbon::now()->subYear();
        $endDate = Carbon::now();
        
        // Payment methods
        $paymentMethods = ['flutterwave', 'paystack', 'bank_transfer', 'cash_on_delivery'];
        
        // Delivery methods
        $deliveryMethods = ['home_delivery', 'pickup'];
        
        // Order statuses with weighted probabilities
        $orderStatuses = [
            Order::STATUS_COMPLETED => 60,
            Order::STATUS_DELIVERED => 10,
            Order::STATUS_PROCESSING => 10,
            Order::STATUS_SHIPPED => 5,
            Order::STATUS_PENDING => 5,
            Order::STATUS_CANCELLED => 5,
            Order::STATUS_REFUNDED => 3,
            Order::STATUS_EXPIRED => 2,
        ];
        
        // Payment statuses
        $paymentStatuses = [
            Order::PAYMENT_PAID => 85,
            Order::PAYMENT_PENDING => 10,
            Order::PAYMENT_FAILED => 5,
        ];
        
        // Define seasonal patterns (multipliers for each month)
        $seasonalPatterns = [
            1 => 0.8,  // January (post-holiday dip)
            2 => 0.7,  // February
            3 => 0.9,  // March
            4 => 1.0,  // April
            5 => 1.1,  // May
            6 => 1.2,  // June
            7 => 1.3,  // July
            8 => 1.2,  // August
            9 => 1.1,  // September
            10 => 1.3, // October
            11 => 1.5, // November (pre-holiday boost)
            12 => 2.0, // December (holiday season)
        ];
        
        // Define weekly patterns (multipliers for each day of the week)
        $weeklyPatterns = [
            0 => 0.7, // Sunday
            1 => 1.0, // Monday
            2 => 1.1, // Tuesday
            3 => 1.2, // Wednesday
            4 => 1.3, // Thursday
            5 => 1.5, // Friday (peak day)
            6 => 1.0, // Saturday
        ];
        
        // Define hourly patterns (multipliers for each hour)
        $hourlyPatterns = [
            0 => 0.2, 1 => 0.1, 2 => 0.1, 3 => 0.1, 4 => 0.1, 5 => 0.2, // Night (low activity)
            6 => 0.3, 7 => 0.5, 8 => 0.7, 9 => 0.9, 10 => 1.0, 11 => 1.1, // Morning
            12 => 1.2, 13 => 1.0, 14 => 0.9, 15 => 1.0, 16 => 1.2, 17 => 1.5, // Afternoon/Evening (peak)
            18 => 1.4, 19 => 1.3, 20 => 1.0, 21 => 0.8, 22 => 0.5, 23 => 0.3, // Evening/Night
        ];
        
        // Define category seasonal preferences
        $categorySeasonalPreferences = [
            1 => [ // Fresh Foods - consistent with slight summer increase
                'spring' => 1.1,
                'summer' => 1.2,
                'fall' => 1.0,
                'winter' => 0.9,
            ],
            2 => [ // Beverages - higher in summer
                'spring' => 1.0,
                'summer' => 1.4,
                'fall' => 0.9,
                'winter' => 0.8,
            ],
            3 => [ // Electronics - higher during holidays
                'spring' => 0.8,
                'summer' => 0.7,
                'fall' => 1.2,
                'winter' => 1.8,
            ],
            4 => [ // Home & Garden - higher in spring/summer
                'spring' => 1.5,
                'summer' => 1.3,
                'fall' => 0.8,
                'winter' => 0.6,
            ],
            5 => [ // Sports & Fitness - higher in January (resolutions) and summer
                'spring' => 1.1,
                'summer' => 1.3,
                'fall' => 0.9,
                'winter' => 1.2,
            ],
            6 => [ // Beauty & Personal Care - consistent with holiday boost
                'spring' => 1.0,
                'summer' => 1.1,
                'fall' => 1.0,
                'winter' => 1.4,
            ],
        ];
        
        // Map months to seasons
        $monthToSeason = [
            1 => 'winter', 2 => 'winter', 3 => 'spring',
            4 => 'spring', 5 => 'spring', 6 => 'summer',
            7 => 'summer', 8 => 'summer', 9 => 'fall',
            10 => 'fall', 11 => 'fall', 12 => 'winter',
        ];
        
        // Process each month in the year
        $currentDate = clone $startDate;
        
        while ($currentDate <= $endDate) {
            $month = (int)$currentDate->format('n');
            $season = $monthToSeason[$month];
            $monthMultiplier = $seasonalPatterns[$month];
            
            // Base number of orders for this month (adjust as needed)
            $baseOrdersPerMonth = 40; // Adjust based on desired yearly total
            $ordersThisMonth = (int)($baseOrdersPerMonth * $monthMultiplier);
            
            // Create orders for this month
            for ($i = 0; $i < $ordersThisMonth; $i++) {
                // Generate a date within this month with realistic day/hour distribution
                $orderDate = $this->generateRealisticDateTime($currentDate, $weeklyPatterns, $hourlyPatterns);
                
                // Select a random user
                $user = $users->random();
                
                // Generate a random order number
                $orderNumber = 'ORD-' . strtoupper(Str::random(8));
                
                // Select a random payment method
                $paymentMethod = $paymentMethods[array_rand($paymentMethods)];
                
                // Select a random delivery method
                $deliveryMethod = $deliveryMethods[array_rand($deliveryMethods)];
                
                // Select a random order status based on weighted probabilities
                $status = $this->getRandomWeightedElement($orderStatuses);
                
                // Select a random payment status based on weighted probabilities
                $paymentStatus = $this->getRandomWeightedElement($paymentStatuses);
                
                // Create the order with default values for required fields
                $order = Order::create([
                    'user_id' => $user->id,
                    'order_number' => $orderNumber,
                    'status' => $status,
                    'payment_method' => $paymentMethod,
                    'payment_status' => $paymentStatus,
                    'delivery_method' => $deliveryMethod,
                    'shipping_address' => '123 Main St',
                    'shipping_city' => 'Lagos',
                    'shipping_state' => 'Lagos',
                    'shipping_zip_code' => '100001',
                    'shipping_phone' => '08012345678',
                    'subtotal' => 0,
                    'tax' => 0,
                    'shipping_fee' => 0,
                    'grand_total' => 0,
                    'created_at' => $orderDate,
                    'updated_at' => $orderDate,
                ]);
                
                // Determine number of items in this order (1-5)
                $numberOfItems = rand(1, 5);
                
                // Keep track of selected products to avoid duplicates in the same order
                $selectedProductIds = [];
                
                // Initialize order totals
                $subtotal = 0;
                
                // Determine which categories are more likely based on season
                $categoryProbabilities = [];
                foreach ($categorySeasonalPreferences as $categoryId => $seasonPreferences) {
                    $categoryProbabilities[$categoryId] = $seasonPreferences[$season];
                }
                
                // Add random items to the order
                for ($j = 0; $j < $numberOfItems; $j++) {
                    // Select a category based on seasonal preferences
                    $categoryId = $this->getRandomWeightedElement($categoryProbabilities);
                    
                    // If no products in this category, pick a random category
                    if (!isset($productsByCategory[$categoryId]) || $productsByCategory[$categoryId]->isEmpty()) {
                        $categoryId = array_rand($productsByCategory);
                    }
                    
                    // Get a random product from this category that hasn't been added to this order yet
                    $availableProducts = $productsByCategory[$categoryId]->filter(function ($product) use ($selectedProductIds) {
                        return !in_array($product->id, $selectedProductIds);
                    });
                    
                    // If no available products in this category, try another category
                    if ($availableProducts->isEmpty()) {
                        // Try to find any product not already in the order
                        $anyProduct = Product::whereNotIn('id', $selectedProductIds)->inRandomOrder()->first();
                        
                        // If still no products available, break the loop
                        if (!$anyProduct) {
                            break;
                        }
                        
                        $product = $anyProduct;
                    } else {
                        $product = $availableProducts->random();
                    }
                    
                    // Add product to selected products
                    $selectedProductIds[] = $product->id;
                    
                    // Determine quantity (1-3)
                    $quantity = rand(1, 3);
                    
                    // Calculate item subtotal
                    $unitPrice = $product->sale_price ?? $product->base_price;
                    $itemSubtotal = $unitPrice * $quantity;
                    
                    // Add to order subtotal
                    $subtotal += $itemSubtotal;
                    
                    // Create order item with all required fields
                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'base_price' => $product->base_price,
                        'subtotal' => $itemSubtotal,
                        'product_measurement_id' => null,
                        'measurement_unit' => 'unit',
                        'measurement_value' => 1,
                        'created_at' => $orderDate,
                        'updated_at' => $orderDate,
                    ]);
                }
                
                // Calculate order totals
                $tax = $subtotal * 0.075; // 7.5% VAT
                $shippingFee = $deliveryMethod === 'home_delivery' ? 1500 : 0;
                $grandTotal = $subtotal + $tax + $shippingFee;
                
                // Update the order with the calculated totals
                $order->update([
                    'subtotal' => $subtotal,
                    'tax' => $tax,
                    'shipping_fee' => $shippingFee,
                    'grand_total' => $grandTotal,
                ]);
                
                // If order is expired, set expired_at date
                if ($status === Order::STATUS_EXPIRED) {
                    $expiredAt = (clone $orderDate)->addDays(3);
                    $order->update(['expired_at' => $expiredAt]);
                }
            }
            
            // Move to next month
            $currentDate->addMonth();
        }
    }
    
    /**
     * Generate a realistic datetime within a month based on weekly and hourly patterns
     */
    private function generateRealisticDateTime(Carbon $monthDate, array $weeklyPatterns, array $hourlyPatterns): Carbon
    {
        $daysInMonth = $monthDate->daysInMonth;
        
        // Create an array of day probabilities for the month
        $dayProbabilities = [];
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $date = (clone $monthDate)->setDay($day);
            $dayOfWeek = (int)$date->format('w'); // 0 (Sunday) to 6 (Saturday)
            $dayProbabilities[$day] = $weeklyPatterns[$dayOfWeek];
        }
        
        // Select a day based on probabilities
        $selectedDay = $this->getRandomWeightedElement($dayProbabilities);
        
        // Select an hour based on probabilities
        $selectedHour = $this->getRandomWeightedElement($hourlyPatterns);
        
        // Create the datetime with random minutes and seconds
        $dateTime = (clone $monthDate)
            ->setDay($selectedDay)
            ->setHour($selectedHour)
            ->setMinute(rand(0, 59))
            ->setSecond(rand(0, 59));
        
        return $dateTime;
    }
    
    /**
     * Get a random element based on weighted probabilities
     */
    private function getRandomWeightedElement(array $weightedValues): string|int
    {
        $rand = mt_rand(1, (int)(array_sum($weightedValues) * 100)) / 100;
        
        foreach ($weightedValues as $key => $value) {
            $rand -= $value;
            if ($rand <= 0) {
                return $key;
            }
        }
        
        return array_key_first($weightedValues);
    }
}
