<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\CouponController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\DebugController;
use App\Http\Controllers\Admin\CategoryAdminController;
use App\Http\Controllers\Admin\ProductSectionController;
use App\Http\Controllers\Admin\BannerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\AddressController;
use App\Http\Controllers\DeliveryFeeController;
use App\Http\Controllers\ProductSectionController as PublicProductSectionController;
use App\Http\Controllers\NotificationBarController;
use App\Http\Controllers\Admin\NotificationBarController as AdminNotificationBarController;
use App\Http\Controllers\Admin\MessageCampaignController;
use App\Http\Controllers\UserNotificationController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\PromotionalSmsController;
use App\Http\Controllers\TaxSettingsController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/auth/google', [SocialAuthController::class, 'googleAuth']);
Route::post('/auth/apple', [SocialAuthController::class, 'appleAuth']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

// Payment routes
Route::post('/payments/paystack/initialize', [\App\Http\Controllers\PaystackController::class, 'initializePayment']);
Route::post('/payments/paystack/verify', [\App\Http\Controllers\PaystackController::class, 'verifyPayment']);
Route::post('/payments/paystack/webhook', [\App\Http\Controllers\PaystackController::class, 'handleWebhook']);

// Test endpoint for social auth
Route::post('/auth/google/test', [SocialAuthController::class, 'testGoogleAuth']);

// Flutterwave webhook (must be public)
Route::post('/webhooks/flutterwave', [PaymentController::class, 'handleWebhook']);

// Store Locations and Pickup Points
Route::get('/pickup-locations', [App\Http\Controllers\StoreAddressController::class, 'getPublicPickupLocations']);
Route::post('/pickup-locations/nearest', [App\Http\Controllers\StoreAddressController::class, 'findNearestPickupLocations']);

// Payment callback routes (must be public)
Route::get('/payments/callback', [PaymentController::class, 'handleCallback'])->name('payment.callback');
Route::get('/payment/callback', [PaymentController::class, 'handleCallback'])->name('payment.callback.alt');
Route::get('/payments/callback/{status}', [PaymentController::class, 'handleCallback'])->name('payment.callback.status');
Route::get('/payment/callback/{status}', [PaymentController::class, 'handleCallback'])->name('payment.callback.alt.status');
Route::get('/payments/verify/{transactionId}', [PaymentController::class, 'verifyTransaction']);

// Products & Categories (Public)
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{product}', [ProductController::class, 'show']);
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/categories/tree', [CategoryController::class, 'tree']);
Route::get('/categories/{category}', [CategoryController::class, 'show']);
Route::get('/categories/{category}/products', [CategoryController::class, 'products']);

// Product Sections (Public)
Route::get('/product-sections', [PublicProductSectionController::class, 'index']);
Route::get('/products/by-type', [PublicProductSectionController::class, 'getProductsByType']);
Route::get('/products/by-type/{type}', [PublicProductSectionController::class, 'getProductsByTypeParam']);

// Coupons (Public validation)
Route::post('/coupons/validate', [CouponController::class, 'validateCoupon']);

// Delivery Fee Calculation (Public)
Route::post('/delivery-fee/calculate', [DeliveryFeeController::class, 'calculate']);

// Store Locations (Public)
Route::get('/locations', [LocationController::class, 'index']);
Route::get('/locations/nearby', [LocationController::class, 'nearby']);
Route::get('/locations/{location}', [LocationController::class, 'show'])->where('location', '[0-9]+');

// Public notification bar
Route::get('/notification-bar', [NotificationBarController::class, 'getActive']);

// Test email route
Route::get('/test-email', function (Request $request) {
    try {
        $user = \App\Models\User::first();
        if (!$user) {
            return response()->json(['error' => 'No users found to test email'], 404);
        }
        
        // Create a test order
        $order = \App\Models\Order::latest()->first();
        if (!$order) {
            return response()->json(['error' => 'No orders found to test email'], 404);
        }
        
        // Log the email attempt
        \Illuminate\Support\Facades\Log::info('Test email route called', [
            'user_email' => $user->email,
            'order_id' => $order->id,
            'order_number' => $order->order_number
        ]);
        
        // Send the email
        \Illuminate\Support\Facades\Mail::to($user->email)
            ->send(new \App\Mail\OrderConfirmationMail($order));
        
        return response()->json([
            'success' => true,
            'message' => 'Test email sent successfully',
            'to' => $user->email,
            'order_id' => $order->id,
            'order_number' => $order->order_number
        ]);
    } catch (\Exception $e) {
        \Illuminate\Support\Facades\Log::error('Test email failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        
        return response()->json([
            'success' => false,
            'message' => 'Failed to send test email',
            'error' => $e->getMessage()
        ], 500);
    }
});

// Public settings endpoint for tax rate
Route::get('/settings', function (Request $request) {
    $keys = $request->query('keys', []);
    $settings = [];
    
    if (!empty($keys)) {
        $settings = \App\Models\Setting::whereIn('key', $keys)->get();
    }
    
    return response()->json([
        'success' => true,
        'data' => $settings
    ]);
});

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // User profile
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    Route::put('/user/profile', [AuthController::class, 'updateProfile']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh-token', [AuthController::class, 'refreshToken']);
    
    // User notifications
    Route::get('/notifications', [UserNotificationController::class, 'index']);
    Route::get('/notifications/unread/count', [UserNotificationController::class, 'getUnreadCount']);
    Route::get('/notifications/{id}', [UserNotificationController::class, 'show']);
    Route::post('/notifications/{id}/read', [UserNotificationController::class, 'markAsRead']);
    Route::post('/notifications/read-all', [UserNotificationController::class, 'markAllAsRead']);
    Route::delete('/notifications/{id}', [UserNotificationController::class, 'destroy']);

    // User Addresses
    Route::get('/users/{userId}/addresses', [AddressController::class, 'index']);
    Route::post('/users/{userId}/addresses', [AddressController::class, 'store']);
    Route::get('/users/{userId}/addresses/{addressId}', [AddressController::class, 'show']);
    Route::put('/users/{userId}/addresses/{addressId}', [AddressController::class, 'update']);
    Route::delete('/users/{userId}/addresses/{addressId}', [AddressController::class, 'destroy']);
    Route::patch('/users/{userId}/addresses/{addressId}/default', [AddressController::class, 'setDefault']);
    Route::patch('/users/{userId}/addresses/{addressId}/coordinates', [AddressController::class, 'updateCoordinates']);
    
    // Cart routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/cart', [CartController::class, 'index']);
        Route::get('/cart/count', [CartController::class, 'count']);
        Route::post('/cart/add', [CartController::class, 'addItem']);
        Route::post('/cart/update/{id}', [CartController::class, 'updateItem']);
        Route::delete('/cart/remove/{id}', [CartController::class, 'removeItem']);
        Route::delete('/cart/clear', [CartController::class, 'clearCart']);
        Route::post('/cart/sync', [CartController::class, 'sync']);
    });
    
    // Checkout & Orders
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);
    
    // Debug routes
    Route::prefix('debug')->group(function () {
        Route::get('/env-variables', [DebugController::class, 'getEnvVariables']);
        Route::post('/test-flutterwave', [DebugController::class, 'testFlutterwaveAPI']);
        Route::get('/payment-details/{orderId}', [DebugController::class, 'getPaymentDetails']);
    });
    
    // Payments
    Route::get('/payments/methods', [PaymentController::class, 'getPaymentMethods']);
    Route::post('/payments/process', [PaymentController::class, 'processPayment']);
    Route::post('/orders/{order}/payment', [PaymentController::class, 'processPayment']);
    Route::get('/payments/{payment}/verify', [PaymentController::class, 'verifyPayment']);
    Route::get('/payments/flutterwave/verify/{transactionId}', [PaymentController::class, 'verifyTransaction']);

    // Store pickup details (only after payment)
    Route::get('/orders/{order}/pickup-details', [OrderController::class, 'pickupDetails']);
});

// Admin routes
Route::middleware(['auth:sanctum'])->prefix('admin')->group(function () {
    // Debug route to verify admin access
    Route::get('/check-auth', function (Request $request) {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'message' => 'Not authenticated',
                    'token_exists' => !empty($request->bearerToken())
                ], 401);
            }

            if ($user->role !== 'admin') {
                return response()->json([
                    'message' => 'Not an admin',
                    'user_role' => $user->role
                ], 403);
            }

            return response()->json([
                'message' => 'Admin authentication successful',
                'user' => $user,
                'is_admin' => true
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error checking authentication',
                'error' => $e->getMessage()
            ], 500);
        }
    });
    
    // Dashboard routes
    Route::prefix('dashboard')->group(function () {
        Route::get('/stats', [ProductController::class, 'getDashboardStats']);
        Route::get('/revenue', [ProductController::class, 'getRevenueData']);
        Route::get('/peak-days', [ProductController::class, 'getPeakDays']);
        Route::get('/peak-hours', [ProductController::class, 'getPeakHours']);
    });
    
    // Settings Management
    Route::get('/settings', [\App\Http\Controllers\Admin\SettingsController::class, 'index']);
    Route::put('/settings', [\App\Http\Controllers\Admin\SettingsController::class, 'update']);
    Route::get('/settings/{key}', [\App\Http\Controllers\Admin\SettingsController::class, 'show']);
    
    // Delivery Settings
    Route::get('/delivery-settings/global', [App\Http\Controllers\Admin\DeliverySettingsController::class, 'getGlobalSettings']);
    Route::put('/delivery-settings/global', [App\Http\Controllers\Admin\DeliverySettingsController::class, 'updateGlobalSettings']);
    Route::get('/delivery-settings/store/{storeId}', [App\Http\Controllers\Admin\DeliverySettingsController::class, 'getStoreSettings']);
    Route::put('/delivery-settings/store/{storeId}', [App\Http\Controllers\Admin\DeliverySettingsController::class, 'updateStoreSettings']);
    
    // Store Address Management
    Route::get('/store-addresses', [App\Http\Controllers\Admin\StoreAddressController::class, 'index']);
    Route::post('/store-addresses', [App\Http\Controllers\Admin\StoreAddressController::class, 'store']);
    Route::get('/store-addresses/{id}', [App\Http\Controllers\Admin\StoreAddressController::class, 'show']);
    Route::put('/store-addresses/{id}', [App\Http\Controllers\Admin\StoreAddressController::class, 'update']);
    Route::delete('/store-addresses/{id}', [App\Http\Controllers\Admin\StoreAddressController::class, 'destroy']);
    Route::get('/pickup-locations', [App\Http\Controllers\Admin\StoreAddressController::class, 'getPickupLocations']);
    
    // Banner Management
    Route::get('/banners', [BannerController::class, 'index']);
    Route::post('/banners', [BannerController::class, 'store']);
    Route::get('/banners/{id}', [BannerController::class, 'show']);
    Route::put('/banners/{id}', [BannerController::class, 'update']);
    Route::delete('/banners/{id}', [BannerController::class, 'destroy']);
    Route::post('/banners/reorder', [BannerController::class, 'reorder']);
    Route::put('/banners/{id}/toggle-status', [BannerController::class, 'toggleStatus']);
    
    // Notification Bar Management
    Route::get('/notification-bar', [AdminNotificationBarController::class, 'index']);
    Route::put('/notification-bar', [AdminNotificationBarController::class, 'update']);
    Route::put('/notification-bar/toggle-status', [AdminNotificationBarController::class, 'toggleStatus']);
    
    // Product Management
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{product}', [ProductController::class, 'show']);
    Route::post('/products', [ProductController::class, 'store']);
    Route::put('/products/{product}', [ProductController::class, 'update']);
    Route::delete('/products/{product}', [ProductController::class, 'destroy']);
    Route::put('/products/{product}/featured', [ProductController::class, 'toggleFeatured']);
    Route::put('/products/{product}/status', [ProductController::class, 'toggleStatus']);
    Route::post('/products/bulk-delete', [ProductController::class, 'bulkDelete']);
    Route::post('/products/bulk-feature', [ProductController::class, 'bulkFeature']);
    Route::post('/products/bulk-status', [ProductController::class, 'bulkStatus']);
    
    // Product Import Routes
    Route::post('/products/import', [ProductController::class, 'importProducts']);
    Route::get('/products/import/template', [ProductController::class, 'downloadImportTemplate']);
    
    // Category Management
    Route::get('/categories/stock-data', [\App\Http\Controllers\Admin\CategoryAdminController::class, 'getCategoryStockData']);
    Route::apiResource('/categories', \App\Http\Controllers\Admin\CategoryAdminController::class);
    Route::get('/categories-tree', [\App\Http\Controllers\Admin\CategoryAdminController::class, 'tree']);
    Route::post('/categories-reorder', [\App\Http\Controllers\Admin\CategoryAdminController::class, 'reorder']);
    
    // Admin Order Management
    Route::prefix('orders')->group(function () {
        Route::get('/', [AdminOrderController::class, 'index']);
        Route::get('/export', [AdminOrderController::class, 'exportOrders']);
        Route::get('/{id}', [AdminOrderController::class, 'show']);
        Route::put('/{id}/status', [AdminOrderController::class, 'updateStatus']);
    });
    Route::get('dashboard/order-stats', [AdminOrderController::class, 'getStats']);
    
    // Coupon Management
    Route::get('/coupons', [CouponController::class, 'index']);
    Route::post('/coupons', [CouponController::class, 'store']);
    Route::put('/coupons/{coupon}', [CouponController::class, 'update']);
    Route::delete('/coupons/{coupon}', [CouponController::class, 'destroy']);
    Route::patch('/coupons/{coupon}/toggle-status', [CouponController::class, 'toggleStatus']);
    
    // Product Section Management
    Route::get('/product-sections', [\App\Http\Controllers\Admin\ProductSectionController::class, 'index']);
    Route::post('/product-sections', [\App\Http\Controllers\Admin\ProductSectionController::class, 'store']);
    Route::get('/product-sections/{id}', [\App\Http\Controllers\Admin\ProductSectionController::class, 'show']);
    Route::put('/product-sections/{id}', [\App\Http\Controllers\Admin\ProductSectionController::class, 'update']);
    Route::delete('/product-sections/{id}', [\App\Http\Controllers\Admin\ProductSectionController::class, 'destroy']);
    Route::patch('/product-sections/{id}/toggle', [\App\Http\Controllers\Admin\ProductSectionController::class, 'toggle']);
    Route::post('/product-sections/reorder', [\App\Http\Controllers\Admin\ProductSectionController::class, 'reorder']);
    
    // Location Management
    Route::get('/locations', [LocationController::class, 'index']);
    Route::get('/locations/{location}', [LocationController::class, 'show']);
    Route::post('/locations', [LocationController::class, 'store']);
    Route::put('/locations/{location}', [LocationController::class, 'update']);
    Route::delete('/locations/{location}', [LocationController::class, 'destroy']);
    Route::put('/locations/radius', [LocationController::class, 'updateRadius']);
    Route::put('/locations/{location}/toggle-status', [LocationController::class, 'toggleStatus']);
    Route::put('/locations/{location}/toggle-pickup', [LocationController::class, 'togglePickup']);
    Route::put('/locations/{location}/toggle-delivery', [LocationController::class, 'toggleDelivery']);
    
    // Payment Management
    Route::get('/orders/{order}/payments', [PaymentController::class, 'adminViewPayment']);
    Route::put('/orders/{order}/payments/status', [PaymentController::class, 'updatePaymentStatus']);
    
    // Message Campaigns
    Route::get('/messages/campaigns', [MessageCampaignController::class, 'index']);
    Route::post('/messages/campaigns', [MessageCampaignController::class, 'store']);
    Route::get('/messages/campaigns/segments', [MessageCampaignController::class, 'getSegments']);
    Route::get('/messages/campaigns/{id}', [MessageCampaignController::class, 'show']);
    Route::put('/messages/campaigns/{id}', [MessageCampaignController::class, 'update']);
    Route::delete('/messages/campaigns/{id}', [MessageCampaignController::class, 'destroy']);
    Route::post('/messages/campaigns/{id}/send', [MessageCampaignController::class, 'send']);
    Route::get('/users/segments', [MessageCampaignController::class, 'getUserSegments']);
    
    // Promotional SMS
    Route::prefix('promotional-sms')->group(function () {
        Route::post('/send-coupon', [PromotionalSmsController::class, 'sendCouponSms']);
        Route::post('/send-custom', [PromotionalSmsController::class, 'sendCustomSms']);
        Route::get('/customers', [PromotionalSmsController::class, 'getCustomersWithPhones']);
        Route::get('/coupons', [PromotionalSmsController::class, 'getAvailableCoupons']);
    });
    
    // User Management
    Route::get('/users', [\App\Http\Controllers\Admin\UserController::class, 'index']);
    Route::post('/users', [\App\Http\Controllers\Admin\UserController::class, 'store']);
    Route::get('/users/{id}', [\App\Http\Controllers\Admin\UserController::class, 'show']);
    Route::put('/users/{id}', [\App\Http\Controllers\Admin\UserController::class, 'update']);
    Route::delete('/users/{id}', [\App\Http\Controllers\Admin\UserController::class, 'destroy']);
    Route::put('/users/{id}/status', [\App\Http\Controllers\Admin\UserController::class, 'updateStatus']);
});

// Debug route (temporary)
Route::get('/debug/user', function (Request $request) {
    return response()->json([
        'user' => $request->user(),
        'ip' => $request->ip(),
        'agent' => $request->userAgent(),
    ]);
});

// Debug route for delivery fee calculation
Route::get('/debug/delivery-fee', function (Request $request) {
    $customerLocation = [6.4376918, 3.4095396]; // Example coordinates from updated address
    $storeLocation = [6.4425335, 3.4908136]; // Example coordinates from active store
    $subtotal = 932.66; // Example subtotal from logs
    
    $deliveryService = new \App\Services\DeliveryFeeService();
    
    // Get the store
    $store = \App\Models\StoreAddress::where('is_active', true)
        ->where('is_delivery_location', true)
        ->first();
        
    if (!$store) {
        return response()->json(['error' => 'No active delivery store found']);
    }
    
    // Calculate distance manually
    $lat1 = $customerLocation[0];
    $lon1 = $customerLocation[1];
    $lat2 = $store->latitude;
    $lon2 = $store->longitude;
    
    $earthRadius = 6371; // Radius of the earth in km
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    $distance = $earthRadius * $c; // Distance in km
    
    // Check if within delivery radius
    $withinRadius = $distance <= $store->delivery_radius_km;
    
    // Calculate delivery fee
    $result = $deliveryService->calculateDeliveryFee($subtotal, $customerLocation);
    
    return response()->json([
        'debug_info' => [
            'customer_location' => $customerLocation,
            'store_location' => [$store->latitude, $store->longitude],
            'store_details' => $store,
            'manual_distance_calculation' => [
                'distance_km' => $distance,
                'within_delivery_radius' => $withinRadius,
                'delivery_radius_km' => $store->delivery_radius_km
            ]
        ],
        'delivery_fee_result' => $result
    ]);
});

// Delivery fee debug route
Route::get('/delivery-fee/debug', [DeliveryFeeController::class, 'debugCalculateDeliveryFee']);
