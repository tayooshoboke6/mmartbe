<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;

class RecentOrdersSeeder extends Seeder
{
    /**
     * Run the database seeds to create very recent orders within the last few hours.
     */
    public function run(): void
    {
        // Get all customer users
        $users = User::where('role', 'customer')->get();
        
        // Get all products
        $products = Product::all();
        
        // Payment methods
        $paymentMethods = ['flutterwave', 'paystack', 'bank_transfer', 'cash_on_delivery'];
        
        // Delivery methods
        $deliveryMethods = ['home_delivery', 'pickup'];
        
        // Order statuses with weighted probabilities
        $orderStatuses = [
            Order::STATUS_COMPLETED => 40,
            Order::STATUS_DELIVERED => 15,
            Order::STATUS_PROCESSING => 25,
            Order::STATUS_SHIPPED => 10,
            Order::STATUS_PENDING => 10,
        ];
        
        // Payment statuses
        $paymentStatuses = [
            Order::PAYMENT_PAID => 85,
            Order::PAYMENT_PENDING => 15,
        ];
        
        // Create 24 orders, one for each of the last 24 hours
        for ($hour = 0; $hour < 24; $hour++) {
            // Create 1-3 orders per hour
            $ordersPerHour = rand(1, 3);
            
            for ($i = 0; $i < $ordersPerHour; $i++) {
                // Generate a timestamp within this hour
                $timestamp = Carbon::now()->subHours($hour)->subMinutes(rand(0, 59))->subSeconds(rand(0, 59));
                
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
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
                
                // Determine number of items in this order (1-3)
                $numberOfItems = rand(1, 3);
                
                // Keep track of selected products to avoid duplicates in the same order
                $selectedProductIds = [];
                
                // Initialize order totals
                $subtotal = 0;
                
                // Add random items to the order
                for ($j = 0; $j < $numberOfItems; $j++) {
                    // Get a random product that hasn't been added to this order yet
                    $availableProducts = $products->filter(function ($product) use ($selectedProductIds) {
                        return !in_array($product->id, $selectedProductIds);
                    });
                    
                    // If no available products, break the loop
                    if ($availableProducts->isEmpty()) {
                        break;
                    }
                    
                    $product = $availableProducts->random();
                    
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
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
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
            }
        }
    }
    
    /**
     * Get a random element based on weighted probabilities
     */
    private function getRandomWeightedElement(array $weightedValues): string
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
