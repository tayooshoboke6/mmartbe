<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Coupon;
use App\Models\Location;
use App\Services\DeliveryFeeService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;

class OrderController extends Controller
{
    protected $deliveryFeeService;

    /**
     * Create a new controller instance.
     *
     * @param DeliveryFeeService $deliveryFeeService
     * @return void
     */
    public function __construct(DeliveryFeeService $deliveryFeeService)
    {
        $this->deliveryFeeService = $deliveryFeeService;
    }

    /**
     * Display a listing of the user's orders.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $query = $request->user()->orders()
            ->with(['items'])
            ->when($request->has('status'), function ($q) use ($request) {
                return $q->where('status', $request->status);
            });

        $orders = $query->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 10);

        return response()->json($orders);
    }

    /**
     * Display a listing of all orders (admin only).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function adminIndex(Request $request)
    {
        $query = Order::with(['user', 'items', 'coupon'])
            ->when($request->has('status'), function ($q) use ($request) {
                return $q->where('status', $request->status);
            })
            ->when($request->has('payment_status'), function ($q) use ($request) {
                return $q->where('payment_status', $request->payment_status);
            })
            ->when($request->has('user_id'), function ($q) use ($request) {
                return $q->where('user_id', $request->user_id);
            })
            ->when($request->has('search'), function ($q) use ($request) {
                return $q->where('order_number', 'like', '%' . $request->search . '%')
                    ->orWhereHas('user', function ($query) use ($request) {
                        $query->where('name', 'like', '%' . $request->search . '%')
                            ->orWhere('email', 'like', '%' . $request->search . '%');
                    });
            });

        $orders = $query->orderBy($request->sort_by ?? 'created_at', $request->sort_direction ?? 'desc')
            ->paginate($request->per_page ?? 15);

        return response()->json([
            'status' => 'success',
            'data' => $orders
        ]);
    }

    /**
     * Store a newly created order in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $user = $request->user();
        
        $validator = Validator::make($request->all(), [
            'payment_method' => 'required|string|in:card,bank_transfer,mobile_money,cash_on_delivery',
            'delivery_method' => 'required|string|in:shipping,pickup',
            'coupon_code' => 'nullable|string|exists:coupons,code',
            'notes' => 'nullable|string',
            'customer_email' => 'nullable|email|max:255',
            'shipping_address' => 'required_if:delivery_method,shipping|string|max:255',
            'shipping_city' => 'required_if:delivery_method,shipping|string|max:100',
            'shipping_state' => 'required_if:delivery_method,shipping|string|max:100',
            'shipping_zip' => 'required_if:delivery_method,shipping|string|max:20',
            'shipping_phone' => 'required_if:delivery_method,shipping|string|max:20',
            'pickup_location_id' => 'required_if:delivery_method,pickup|exists:locations,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Get cart items
        $cartItems = $user->cartItems()->with(['product', 'measurement'])->get();
        
        if ($cartItems->isEmpty()) {
            return response()->json(['message' => 'Cart is empty'], 422);
        }

        // Check product availability
        foreach ($cartItems as $cartItem) {
            $product = $cartItem->product;
            $measurement = $cartItem->measurement;
            
            if (!$product->is_active) {
                return response()->json([
                    'message' => "Product '{$product->name}' is no longer available",
                ], 422);
            }
            
            $stock = $measurement ? $measurement->stock_quantity : $product->stock_quantity;
            
            if ($stock < $cartItem->quantity) {
                return response()->json([
                    'message' => "Not enough stock for '{$product->name}'",
                ], 422);
            }
        }

        DB::beginTransaction();

        try {
            // Calculate order amounts
            $totalAmount = 0;
            $taxAmount = 0;
            $shippingAmount = 0; // Initialize shipping amount
            
            foreach ($cartItems as $cartItem) {
                $price = $cartItem->product->getCurrentPrice();
                
                // Apply measurement price adjustment if applicable
                if ($cartItem->measurement && $cartItem->measurement->price_adjustment) {
                    $price += $cartItem->measurement->price_adjustment;
                }
                
                $totalAmount += $price * $cartItem->quantity;
            }
            
            // Apply tax (example: 8%)
            $taxAmount = $totalAmount * 0.08;
            
            // Calculate shipping fee based on delivery method
            if ($request->delivery_method === 'shipping') {
                // If shipping fee is already provided in the request, use it
                if ($request->has('shipping_fee')) {
                    $shippingAmount = (float) $request->shipping_fee;
                    \Log::info('Using shipping fee from request', ['shipping_fee' => $shippingAmount]);
                }
                // Otherwise calculate it based on coordinates if available
                else if ($request->has('shipping_latitude') && $request->has('shipping_longitude')) {
                    $customerLocation = [
                        $request->shipping_latitude,
                        $request->shipping_longitude
                    ];
                    
                    try {
                        $deliveryDetails = $this->deliveryFeeService->calculateDeliveryFee(
                            $totalAmount,
                            $customerLocation,
                            $request->store_id
                        );
                        
                        // Only apply fee if delivery is available
                        if ($deliveryDetails['isDeliveryAvailable']) {
                            $shippingAmount = $deliveryDetails['fee'];
                        } else {
                            // Log the issue but continue with default fee instead of returning error
                            \Log::warning('Delivery not available for location, using default fee', [
                                'message' => $deliveryDetails['message'],
                                'customer_location' => $customerLocation,
                                'store_id' => $request->store_id
                            ]);
                            $shippingAmount = 500; // Default ₦500
                        }
                    } catch (\Exception $e) {
                        \Log::error('Error calculating delivery fee', [
                            'error' => $e->getMessage(),
                            'location' => $customerLocation,
                            'store_id' => $request->store_id
                        ]);
                        // Use default fee instead of failing
                        $shippingAmount = 500; // Default ₦500
                    }
                } else {
                    // Fallback to default shipping fee if coordinates not provided
                    \Log::info('No coordinates provided for delivery fee calculation, using default fee');
                    $shippingAmount = 500; // Default ₦500
                }
            }
            
            // Apply coupon if provided
            $discountAmount = 0;
            $couponId = null;
            
            // If discount is already provided in the request, use it
            if ($request->has('discount')) {
                $discountAmount = (float) $request->discount;
                \Log::info('Using discount from request', ['discount' => $discountAmount]);
            }
            // Otherwise calculate it based on coupon code if available
            else if ($request->has('coupon_code')) {
                $coupon = Coupon::where('code', $request->coupon_code)->first();
                
                if ($coupon && $coupon->isValid($totalAmount, $user->id)) {
                    $discountAmount = $coupon->calculateDiscount($totalAmount);
                    $couponId = $coupon->id;
                    
                    // Increment coupon usage count
                    $coupon->increment('used_count');
                }
            }
            
            // Calculate grand total
            $grandTotal = $totalAmount + $taxAmount + $shippingAmount - $discountAmount;
            
            // Ensure all required fields are set
            if ($totalAmount <= 0) {
                throw new \Exception('Invalid order total amount');
            }
            
            // Debug the values
            \Log::info('Order values', [
                'totalAmount' => $totalAmount,
                'taxAmount' => $taxAmount,
                'shippingAmount' => $shippingAmount,
                'discountAmount' => $discountAmount,
                'grandTotal' => $grandTotal,
                'cartItems' => $cartItems->count()
            ]);
            
            // Create order with explicit values for all required fields
            $orderData = [
                'user_id' => $user->id,
                'order_number' => 'ORD-' . strtoupper(Str::random(10)),
                'status' => Order::STATUS_PENDING,
                'subtotal' => (float) $totalAmount,
                'discount' => (float) $discountAmount,
                'tax' => (float) $taxAmount,
                'shipping_fee' => (float) $shippingAmount,
                'grand_total' => (float) $grandTotal,
                'payment_method' => $request->payment_method,
                'payment_status' => Order::PAYMENT_PENDING,
                'delivery_method' => $request->delivery_method,
                'coupon_id' => $couponId,
                'delivery_notes' => $request->notes,
                'shipping_address' => $request->shipping_address,
                'shipping_city' => $request->shipping_city,
                'shipping_state' => $request->shipping_state,
                'shipping_zip_code' => $request->shipping_zip,
                'shipping_phone' => $request->shipping_phone,
                'pickup_location_id' => $request->pickup_location_id,
            ];
            
            $order = Order::create($orderData);
            
            // Create order items
            foreach ($cartItems as $cartItem) {
                $product = $cartItem->product;
                $measurement = $cartItem->measurement;
                $price = $product->getCurrentPrice();
                $basePrice = $product->base_price; // Get the base price
                
                // Apply measurement price adjustment if applicable
                if ($measurement && $measurement->price_adjustment) {
                    $price += $measurement->price_adjustment;
                }
                
                $orderItem = OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'quantity' => $cartItem->quantity,
                    'unit_price' => $price,
                    'base_price' => $basePrice, // Store the base price
                    'subtotal' => $price * $cartItem->quantity,
                    'product_measurement_id' => $measurement ? $measurement->id : null,
                    'measurement_unit' => $measurement ? $measurement->unit : 'unit',
                    'measurement_value' => $measurement ? $measurement->value : '0',
                ]);
                
                // Update stock
                if ($measurement) {
                    $measurement->decrement('stock_quantity', $cartItem->quantity);
                } else {
                    $product->decrement('stock_quantity', $cartItem->quantity);
                }
            }
            
            // Clear cart
            $user->cartItems()->delete();
            
            // Send order confirmation email for cash on delivery orders
            $emailSent = false;
            if ($request->payment_method === 'cash_on_delivery') {
                try {
                    // Use the customer's email from the checkout form if provided, otherwise use the user's email
                    $customerEmail = $request->customer_email ?? $user->email;
                    
                    \Log::info('Sending order confirmation email for cash on delivery order', [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'user_email' => $user->email,
                        'customer_email' => $customerEmail
                    ]);
                    
                    // Use NotificationService instead of direct Mail call
                    $emailSent = NotificationService::sendOrderConfirmation($order);
                    
                    if ($emailSent) {
                        \Log::info('Order confirmation email sent successfully', [
                            'order_id' => $order->id,
                            'customer_email' => $customerEmail
                        ]);
                    }
                    
                    // Send SMS notification as well
                    $smsSent = NotificationService::sendOrderConfirmationSms($order);
                    
                    if ($smsSent) {
                        \Log::info('Order confirmation SMS sent successfully', [
                            'order_id' => $order->id,
                            'customer_phone' => $order->user->phone ?? 'N/A'
                        ]);
                    }
                    
                } catch (\Exception $e) {
                    \Log::error('Failed to send order confirmation email', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    
                    $emailSent = false;
                }
            }
            
            DB::commit();
            
            return response()->json([
                'message' => 'Order created successfully',
                'order' => $order->fresh(['items']),
                'email_sent' => $emailSent
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to create order: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified order.
     *
     * @param  int  $id
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function show($id, Request $request)
    {
        $user = $request->user();
        
        $query = Order::with([
            'items.product',
            'items.measurement',
            'coupon',
            'user',
            'pickupLocation'
        ]);
        
        // Regular users can only view their own orders
        if (!$user->hasRole('admin')) {
            $query->where('user_id', $user->id);
        }
        
        // Check if the ID is numeric or an order number
        if (is_numeric($id)) {
            $order = $query->findOrFail($id);
        } else {
            // If not numeric, try to find by order number
            $order = $query->where('order_number', $id)->firstOrFail();
        }
        
        return response()->json([
            'status' => 'success',
            'data' => $order
        ]);
    }

    /**
     * Cancel an order.
     *
     * @param  int  $id
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function cancel($id, Request $request)
    {
        $user = $request->user();
        
        $query = Order::with('items');
        
        // Regular users can only cancel their own orders
        if (!$user->hasRole('admin')) {
            $query->where('user_id', $user->id);
        }
        
        // Check if the ID is numeric or an order number
        if (is_numeric($id)) {
            $order = $query->findOrFail($id);
        } else {
            // If not numeric, try to find by order number
            $order = $query->where('order_number', $id)->firstOrFail();
        }
        
        // Check if order can be cancelled
        if (!in_array($order->status, [Order::STATUS_PENDING, Order::STATUS_PROCESSING])) {
            return response()->json([
                'message' => 'Order cannot be cancelled',
            ], 422);
        }
        
        DB::beginTransaction();
        
        try {
            // Update order status
            $order->update([
                'status' => Order::STATUS_CANCELLED,
            ]);
            
            // Restore stock
            foreach ($order->items as $item) {
                if ($item->measurement_id) {
                    $measurement = $item->measurement;
                    if ($measurement) {
                        $measurement->increment('stock_quantity', $item->quantity);
                    }
                } else {
                    $product = $item->product;
                    if ($product) {
                        $product->increment('stock_quantity', $item->quantity);
                    }
                }
            }
            
            // If coupon was used, decrement usage count
            if ($order->coupon_id) {
                $coupon = $order->coupon;
                if ($coupon && $coupon->used_count > 0) {
                    $coupon->decrement('used_count');
                }
            }
            
            DB::commit();
            
            return response()->json([
                'message' => 'Order cancelled successfully',
                'order' => $order->fresh(['items']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to cancel order: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Update order status (admin only).
     *
     * @param  int  $id
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function updateStatus($id, Request $request)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|string|in:pending,processing,completed,cancelled,refunded',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        $order = Order::findOrFail($id);
        
        // Check if status is changing to cancelled or refunded
        $restoreStock = false;
        if (($request->status === Order::STATUS_CANCELLED || $request->status === Order::STATUS_REFUNDED) && 
            !in_array($order->status, [Order::STATUS_CANCELLED, Order::STATUS_REFUNDED])) {
            $restoreStock = true;
        }
        
        DB::beginTransaction();
        
        try {
            $order->update([
                'status' => $request->status,
            ]);
            
            // Restore stock if order is cancelled or refunded
            if ($restoreStock) {
                foreach ($order->items as $item) {
                    if ($item->measurement_id) {
                        $measurement = $item->measurement;
                        if ($measurement) {
                            $measurement->increment('stock_quantity', $item->quantity);
                        }
                    } else {
                        $product = $item->product;
                        if ($product) {
                            $product->increment('stock_quantity', $item->quantity);
                        }
                    }
                }
                
                // If coupon was used, decrement usage count
                if ($order->coupon_id) {
                    $coupon = $order->coupon;
                    if ($coupon && $coupon->used_count > 0) {
                        $coupon->decrement('used_count');
                    }
                }
            }
            
            DB::commit();
            
            return response()->json([
                'message' => 'Order status updated successfully',
                'order' => $order->fresh(['items']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to update order status: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get pickup details for an order (only visible after payment).
     *
     * @param  int  $id
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function pickupDetails($id, Request $request)
    {
        $user = $request->user();
        
        $query = Order::with('pickupLocation');
        
        // Regular users can only view their own orders
        if (!$user->hasRole('admin')) {
            $query->where('user_id', $user->id);
        }
        
        $order = $query->findOrFail($id);
        
        // Check if order is for pickup
        if (!$order->is_pickup) {
            return response()->json([
                'message' => 'Order is not for pickup',
            ], 422);
        }
        
        // Check if order is ready for pickup (status is processing)
        if ($order->status !== Order::STATUS_PROCESSING && !$user->hasRole('admin')) {
            return response()->json([
                'message' => 'Order is not ready for pickup yet',
            ], 403);
        }
        
        $pickupDetails = $order->getPickupDetails();
        
        if (!$pickupDetails) {
            return response()->json([
                'message' => 'Pickup details not available',
            ], 404);
        }
        
        return response()->json($pickupDetails);
    }

    /**
     * Export orders as CSV with optional filters
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function exportOrders(Request $request)
    {
        try {
            // Log request parameters for debugging
            \Log::info('Export orders request', [
                'params' => $request->all(),
                'user_agent' => $request->header('User-Agent')
            ]);

            // Get filter parameters
            $status = $request->input('status');
            $paymentMethod = $request->input('payment_method');
            $search = $request->input('search');
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

            \Log::info('Export filters', [
                'status' => $status,
                'payment_method' => $paymentMethod,
                'search' => $search,
                'start_date' => $startDate,
                'end_date' => $endDate
            ]);

            // Build query with filters
            $query = Order::with('items');

            // Apply filters
            if ($status && $status !== 'all') {
                $query->where('status', $status);
            }

            if ($paymentMethod && $paymentMethod !== 'all') {
                $query->where('payment_method', $paymentMethod);
            }

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('order_number', 'like', "%{$search}%")
                      ->orWhere('customer_name', 'like', "%{$search}%")
                      ->orWhere('customer_email', 'like', "%{$search}%")
                      ->orWhere('customer_phone', 'like', "%{$search}%");
                });
            }

            if ($startDate) {
                $query->whereDate('created_at', '>=', $startDate);
            }

            if ($endDate) {
                $query->whereDate('created_at', '<=', $endDate);
            }

            // Order by created_at descending
            $query->orderBy('created_at', 'desc');

            // Log the SQL query for debugging
            \Log::info('Export query', ['sql' => $query->toSql(), 'bindings' => $query->getBindings()]);

            // Get all orders that match the filters
            $orders = $query->get();
            
            \Log::info('Found orders for export', ['count' => $orders->count()]);

            // Create CSV response
            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="orders-export-' . date('Y-m-d') . '.csv"',
                'Pragma' => 'no-cache',
                'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
                'Expires' => '0',
            ];

            $callback = function () use ($orders) {
                $file = fopen('php://output', 'w');
                
                // Add CSV headers
                fputcsv($file, [
                    'Order ID',
                    'Order Number',
                    'Customer Name',
                    'Customer Email',
                    'Customer Phone',
                    'Total Amount',
                    'Payment Method',
                    'Payment Status',
                    'Order Status',
                    'Items',
                    'Created At',
                    'Updated At'
                ]);

                // Add order data
                foreach ($orders as $order) {
                    try {
                        // Format items as a string
                        $items = $order->items->map(function ($item) {
                            return $item->quantity . 'x ' . $item->product_name . ' (' . number_format($item->unit_price, 2) . ')';
                        })->implode(', ');

                        fputcsv($file, [
                            $order->id,
                            $order->order_number,
                            $order->customer_name,
                            $order->customer_email,
                            $order->customer_phone ?? 'N/A',
                            number_format($order->grand_total, 2),
                            $order->payment_method,
                            $order->payment_status,
                            $order->status,
                            $items,
                            $order->created_at,
                            $order->updated_at
                        ]);
                    } catch (\Exception $e) {
                        \Log::error('Error processing order for CSV', [
                            'order_id' => $order->id,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);
                    }
                }

                fclose($file);
            };

            return response()->stream($callback, 200, $headers);
        } catch (\Exception $e) {
            \Log::error('Export orders error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Export failed',
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 500);
        }
    }
}
