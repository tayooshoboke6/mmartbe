<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;

class OrderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get all customer users
        $users = User::where('role', 'customer')->get();
        
        // Get all products
        $products = Product::all();
        
        // Define the start date (1 year ago)
        $startDate = Carbon::now()->subYear();
        $endDate = Carbon::now();
        
        // Define the number of orders to create
        $numberOfOrders = 500; // Adjust as needed
        
        // Payment methods
        $paymentMethods = ['card', 'bank_transfer', 'cash_on_delivery'];
        
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
        
        // Create orders
        for ($i = 0; $i < $numberOfOrders; $i++) {
            // Generate a random date between start and end date
            $orderDate = $this->getRandomDateBetween($startDate, $endDate);
            
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
            
            // Create the order
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
                'created_at' => $orderDate,
                'updated_at' => $orderDate,
            ]);
            
            // Determine number of items in this order (1-5)
            $numberOfItems = rand(1, 5);
            
            // Keep track of selected products to avoid duplicates in the same order
            $selectedProductIds = [];
            
            // Initialize order totals
            $subtotal = 0;
            
            // Add random items to the order
            for ($j = 0; $j < $numberOfItems; $j++) {
                // Get a random product that hasn't been added to this order yet
                do {
                    $product = $products->random();
                } while (in_array($product->id, $selectedProductIds));
                
                // Add product to selected products
                $selectedProductIds[] = $product->id;
                
                // Determine quantity (1-3)
                $quantity = rand(1, 3);
                
                // Calculate item subtotal
                $unitPrice = $product->sale_price ?? $product->base_price;
                $itemSubtotal = $unitPrice * $quantity;
                
                // Add to order subtotal
                $subtotal += $itemSubtotal;
                
                // Create order item
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'base_price' => $product->base_price,
                    'subtotal' => $itemSubtotal,
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
    }
    
    /**
     * Get a random date between two dates
     */
    private function getRandomDateBetween(Carbon $startDate, Carbon $endDate): Carbon
    {
        $startTimestamp = $startDate->timestamp;
        $endTimestamp = $endDate->timestamp;
        $randomTimestamp = mt_rand($startTimestamp, $endTimestamp);
        
        return Carbon::createFromTimestamp($randomTimestamp);
    }
    
    /**
     * Get a random element based on weighted probabilities
     */
    private function getRandomWeightedElement(array $weightedValues): string
    {
        $rand = mt_rand(1, array_sum($weightedValues));
        
        foreach ($weightedValues as $key => $value) {
            $rand -= $value;
            if ($rand <= 0) {
                return $key;
            }
        }
        
        return array_key_first($weightedValues);
    }
}
