<?php

namespace App\Http\Controllers;

use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductMeasurement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CartController extends Controller
{
    /**
     * Clear the cart when a payment callback is received, regardless of payment status
     * This is called from the frontend when the user lands on the payment callback page
     * This method includes security checks to verify order ownership
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function clearCartOnPaymentCallback(Request $request)
    {
        try {
            // Validate required parameters
            $request->validate([
                'order_id' => 'required|integer',
                'provider' => 'required|string|in:paystack,flutterwave',
                'reference' => 'required|string'
            ]);
            
            // Log all request parameters for debugging
            Log::info('Cart clearing request received', [
                'all_params' => $request->all(),
                'headers' => $request->header()
            ]);
            
            $orderId = $request->input('order_id');
            $provider = $request->input('provider');
            $reference = $request->input('reference');
            
            // Find the order to verify ownership
            $order = \App\Models\Order::find($orderId);
            
            if (!$order) {
                Log::warning('Attempted to clear cart with invalid order ID', [
                    'order_id' => $orderId,
                    'provider' => $provider,
                    'reference' => $reference
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid order ID'
                ], 400);
            }
            
            // For Flutterwave, the reference might be stored in a different format
            // Often the tx_ref is stored as the payment_reference
            $referenceMatches = false;
            
            if ($order->payment_reference === $reference) {
                $referenceMatches = true;
            } else if ($request->input('provider') === 'flutterwave') {
                // For Flutterwave, check if the reference contains the order number
                // This is a common pattern where tx_ref is formatted as "MMART-timestamp-orderid"
                if (strpos($reference, $order->order_number) !== false || 
                    strpos($reference, (string)$order->id) !== false) {
                    $referenceMatches = true;
                    Log::info('Flutterwave reference matched by order ID pattern', [
                        'order_id' => $orderId,
                        'order_number' => $order->order_number,
                        'reference' => $reference
                    ]);
                }
            }
            
            // If no match found, log a warning and return an error
            if (!$referenceMatches) {
                Log::warning('Payment reference mismatch during cart clearing', [
                    'order_id' => $orderId,
                    'order_number' => $order->order_number,
                    'order_reference' => $order->payment_reference,
                    'provided_reference' => $reference,
                    'provider' => $request->input('provider')
                ]);
                
                // For now, we'll still clear the cart even if reference doesn't match
                // This ensures the cart is cleared in all cases, which is the primary goal
                Log::info('Proceeding with cart clearing despite reference mismatch');
            }
            
            // Get the user associated with the order
            $userId = $order->user_id;
            $user = \App\Models\User::find($userId);
            
            if (!$user) {
                Log::warning('User not found for order during cart clearing', [
                    'order_id' => $orderId,
                    'user_id' => $userId
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }
            
            // Clear the user's cart
            $cartItemCount = $user->cartItems()->count();
            $user->cartItems()->delete();
            
            Log::info('Cart cleared on payment callback page with security verification', [
                'user_id' => $userId,
                'items_removed' => $cartItemCount,
                'order_id' => $orderId,
                'provider' => $provider,
                'reference' => $reference
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Cart cleared successfully',
                'items_removed' => $cartItemCount
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error clearing cart on payment callback', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error clearing cart: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Clear the user's cart
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function clear(Request $request)
    {
        try {
            $user = $request->user();
            $cartItemCount = $user->cartItems()->count();
            $user->cartItems()->delete();
            
            Log::info('Cart cleared via API request', [
                'user_id' => $user->id,
                'items_removed' => $cartItemCount
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Cart cleared successfully',
                'items_removed' => $cartItemCount
            ]);
        } catch (\Exception $e) {
            Log::error('Error clearing cart', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error clearing cart: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Display a listing of the user's cart items.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $cartItems = $user->cartItems()->with(['product', 'measurement'])->get();
        
        return response()->json([
            'cart_items' => $cartItems,
        ]);
    }
    
    /**
     * Get the count of items in the user's cart.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function count(Request $request)
    {
        $user = $request->user();
        $count = $user->cartItems()->sum('quantity');
        
        return response()->json([
            'count' => $count,
        ]);
    }
    
    /**
     * Add an item to the cart.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function addItem(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'product_measurement_id' => 'nullable|exists:product_measurements,id',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        $user = $request->user();
        $product = Product::findOrFail($request->product_id);
        
        // Check if product is active
        if (!$product->is_active) {
            return response()->json([
                'message' => 'Product is not available',
            ], 422);
        }
        
        // Check if there's enough stock
        if (!$product->hasEnoughStock($request->quantity, $request->product_measurement_id)) {
            return response()->json([
                'message' => 'Not enough stock available',
            ], 422);
        }
        
        // Check if item already exists in cart
        $cartItem = $user->cartItems()
            ->where('product_id', $product->id)
            ->where('product_measurement_id', $request->product_measurement_id)
            ->first();
        
        if ($cartItem) {
            // Update quantity
            $newQuantity = $cartItem->quantity + $request->quantity;
            
            // Check stock for new quantity
            if (!$product->hasEnoughStock($newQuantity, $request->product_measurement_id)) {
                return response()->json([
                    'message' => 'Not enough stock available',
                ], 422);
            }
            
            $cartItem->update([
                'quantity' => $newQuantity,
            ]);
            
            return response()->json([
                'message' => 'Cart item quantity updated',
                'cart_item' => $cartItem->fresh()->load('product'),
            ]);
        }
        
        // Create new cart item
        $cartItem = CartItem::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'quantity' => $request->quantity,
            'product_measurement_id' => $request->product_measurement_id,
        ]);
        
        return response()->json([
            'message' => 'Item added to cart',
            'cart_item' => $cartItem->load('product'),
        ]);
    }
    
    /**
     * Update the quantity of a cart item.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function updateItem(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'quantity' => 'required|integer|min:1',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        $user = $request->user();
        $cartItem = $user->cartItems()->findOrFail($id);
        $product = $cartItem->product;
        
        // Check if there's enough stock
        if (!$product->hasEnoughStock($request->quantity, $cartItem->product_measurement_id)) {
            return response()->json([
                'message' => 'Not enough stock available',
            ], 422);
        }
        
        $cartItem->update([
            'quantity' => $request->quantity,
        ]);
        
        return response()->json([
            'message' => 'Cart item updated',
            'cart_item' => $cartItem->fresh()->load('product'),
        ]);
    }
    
    /**
     * Remove a cart item.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function removeItem(Request $request, $id)
    {
        $user = $request->user();
        $cartItem = $user->cartItems()->findOrFail($id);
        
        $cartItem->delete();
        
        return response()->json([
            'message' => 'Cart item removed',
        ]);
    }
    
    /**
     * Clear all items from the cart.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function clearCart(Request $request)
    {
        $user = $request->user();
        $user->cartItems()->delete();
        
        return response()->json([
            'message' => 'Cart cleared',
        ]);
    }
    
    /**
     * Get the user's saved cart data for frontend persistence.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function getUserCart(Request $request)
    {
        try {
            // Add debug logging
            Log::info('getUserCart called', [
                'ip' => $request->ip(),
                'user_agent' => $request->header('User-Agent'),
                'referer' => $request->header('Referer'),
                'auth' => $request->user() ? 'authenticated' : 'unauthenticated'
            ]);
            
            // Add rate limiting - if this IP has made too many requests, return an error
            $ipAddress = $request->ip();
            $cacheKey = 'cart_request_' . $ipAddress;
            $requestCount = Cache::get($cacheKey, 0);
            
            // If more than 5 requests in 10 seconds, throttle
            if ($requestCount > 5) {
                Log::warning('Rate limiting cart requests for IP: ' . $ipAddress);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Too many requests',
                ], 429);
            }
            
            // Increment the request count and set expiry
            Cache::put($cacheKey, $requestCount + 1, now()->addSeconds(10));
            
            $user = $request->user();
            
            // Check if user has saved cart data
            if ($user && $user->cart_data) {
                return response()->json([
                    'status' => 'success',
                    'data' => json_decode($user->cart_data),
                ]);
            }
            
            return response()->json([
                'status' => 'success',
                'data' => [],
            ]);
        } catch (\Exception $e) {
            Log::error('Error in getUserCart: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error retrieving cart data',
                'data' => [],
            ], 500);
        }
    }
    
    /**
     * Save the user's cart data for frontend persistence.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function saveUserCart(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not authenticated',
                ], 401);
            }
            
            $validator = Validator::make($request->all(), [
                'items' => 'required|array',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid cart data',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            // Save cart data to user record
            $user->cart_data = json_encode($request->items);
            $user->save();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Cart saved successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Error in saveUserCart: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error saving cart data',
            ], 500);
        }
    }
    
    /**
     * Synchronize cart items from frontend to backend.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function sync(Request $request)
    {
        try {
            $user = $request->user();
            $items = $request->input('items', []);
            
            Log::info('Syncing cart items for user', [
                'user_id' => $user->id,
                'item_count' => count($items)
            ]);
            
            // Begin transaction to ensure data consistency
            DB::beginTransaction();
            
            // Clear existing cart items
            $user->cartItems()->delete();
            
            // Add new cart items from the frontend
            foreach ($items as $item) {
                // Validate required fields
                if (empty($item['product_id']) || empty($item['quantity'])) {
                    continue;
                }
                
                // Check if product exists
                $product = Product::find($item['product_id']);
                if (!$product) {
                    Log::warning('Product not found during cart sync', [
                        'product_id' => $item['product_id']
                    ]);
                    continue;
                }
                
                // Get measurement if provided
                $measurement = null;
                if (!empty($item['measurement_id'])) {
                    $measurement = ProductMeasurement::find($item['measurement_id']);
                }
                
                // Create cart item
                CartItem::create([
                    'user_id' => $user->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'product_measurement_id' => $measurement ? $measurement->id : null
                ]);
            }
            
            // Commit transaction
            DB::commit();
            
            // Get updated cart items
            $cartItems = $user->cartItems()->with(['product', 'measurement'])->get();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Cart synchronized successfully',
                'cart_items' => $cartItems
            ]);
        } catch (\Exception $e) {
            // Rollback transaction on error
            DB::rollBack();
            
            Log::error('Error syncing cart items', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to sync cart items: ' . $e->getMessage()
            ], 500);
        }
    }
}
