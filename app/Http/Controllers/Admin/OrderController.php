<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class OrderController extends Controller
{
    /**
     * Display a listing of all orders with filtering options.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        try {
            Log::info('Admin orders index called with params: ' . json_encode($request->all()));
            
            $query = Order::with(['user', 'items'])
                ->when($request->has('status') && $request->status, function ($q) use ($request) {
                    return $q->where('status', $request->status);
                })
                ->when($request->has('payment_status') && $request->payment_status, function ($q) use ($request) {
                    return $q->where('payment_status', $request->payment_status);
                })
                ->when($request->has('from_date') && $request->from_date, function ($q) use ($request) {
                    return $q->whereDate('created_at', '>=', $request->from_date);
                })
                ->when($request->has('to_date') && $request->to_date, function ($q) use ($request) {
                    return $q->whereDate('created_at', '<=', $request->to_date);
                })
                ->when($request->has('search') && $request->search, function ($q) use ($request) {
                    $search = $request->search;
                    return $q->where(function ($query) use ($search) {
                        $query->where('order_number', 'like', "%{$search}%")
                            ->orWhereHas('user', function ($userQuery) use ($search) {
                                $userQuery->where('name', 'like', "%{$search}%")
                                    ->orWhere('email', 'like', "%{$search}%");
                            });
                    });
                });

            // Sort orders
            $sortBy = $request->input('sort_by', 'created_at');
            $sortOrder = $request->input('sort_order', 'desc');
            
            if ($sortBy === 'date') {
                $sortBy = 'created_at';
            }
            
            $query->orderBy($sortBy, $sortOrder);
            
            // Paginate results
            $perPage = $request->input('per_page', 10);
            $orders = $query->paginate($perPage);
            
            // Transform orders for frontend
            $transformedOrders = $orders->map(function ($order) {
                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'customer_name' => $order->user ? $order->user->name : 'Guest',
                    'total' => $order->grand_total,
                    'status' => $order->status,
                    'items_count' => $order->items->count(),
                    'payment_method' => $order->payment_method,
                    'payment_status' => $order->payment_status,
                    'created_at' => $order->created_at,
                    'updated_at' => $order->updated_at,
                    'user_id' => $order->user_id
                ];
            });
            
            // Log the response we're sending back
            Log::info('Sending orders response with ' . count($transformedOrders) . ' orders');
            
            // Return in the format expected by the frontend
            return response()->json([
                'status' => 'success',
                'data' => [
                    'data' => $transformedOrders, // Changed to match Laravel's default pagination format
                    'total' => $orders->total(),
                    'current_page' => $orders->currentPage(),
                    'per_page' => $orders->perPage(),
                    'last_page' => $orders->lastPage(),
                    'from' => $orders->firstItem(),
                    'to' => $orders->lastItem()
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching admin orders: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch orders'
            ], 500);
        }
    }

    /**
     * Display the specified order with detailed information.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        try {
            $order = Order::with(['user', 'items.product'])
                ->findOrFail($id);
            
            // Log the order details for debugging
            Log::info('Admin fetching order details for order #' . $id);
            
            return response()->json([
                'status' => 'success',
                'data' => $order
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching order details: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Order not found'
            ], 404);
        }
    }

    /**
     * Update the order status.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function updateStatus(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'status' => 'required|string|in:pending,processing,shipped,delivered,completed,cancelled,refunded',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid status',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            $order = Order::findOrFail($id);
            $oldStatus = $order->status;
            $newStatus = $request->status;
            
            // Update payment status based on order status
            $paymentStatus = $order->payment_status;
            if ($newStatus === Order::STATUS_COMPLETED && $paymentStatus === Order::PAYMENT_PENDING) {
                $paymentStatus = Order::PAYMENT_PAID;
            } elseif ($newStatus === Order::STATUS_CANCELLED && $paymentStatus === Order::PAYMENT_PENDING) {
                $paymentStatus = Order::PAYMENT_FAILED;
            } elseif ($newStatus === Order::STATUS_REFUNDED) {
                $paymentStatus = Order::PAYMENT_REFUNDED;
            } elseif (($newStatus === Order::STATUS_SHIPPED || $newStatus === Order::STATUS_DELIVERED) && $paymentStatus === Order::PAYMENT_PENDING) {
                // If order is shipped or delivered, payment should be marked as paid
                $paymentStatus = Order::PAYMENT_PAID;
            }
            
            $order->update([
                'status' => $newStatus,
                'payment_status' => $paymentStatus
            ]);
            
            // Log status change
            Log::info("Order #{$order->order_number} status changed from {$oldStatus} to {$newStatus} by admin");
            
            // Send order status update email to customer
            try {
                NotificationService::sendOrderStatusUpdate($order, $oldStatus, $newStatus);
                Log::info("Order status update email sent for order #{$order->order_number}");
            } catch (\Exception $e) {
                Log::error("Failed to send order status update email: " . $e->getMessage());
            }
            
            // Send order status update SMS to customer
            try {
                NotificationService::sendOrderStatusUpdateSms($order, $oldStatus, $newStatus);
                Log::info("Order status update SMS sent for order #{$order->order_number}");
            } catch (\Exception $e) {
                Log::error("Failed to send order status update SMS: " . $e->getMessage());
            }
            
            return response()->json([
                'status' => 'success',
                'message' => 'Order status updated successfully',
                'data' => $order
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating order status: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update order status'
            ], 500);
        }
    }

    /**
     * Get order statistics for the dashboard.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function getStats(Request $request)
    {
        try {
            // Default to last 30 days if no date range specified
            $startDate = $request->input('start_date', now()->subDays(30)->toDateString());
            $endDate = $request->input('end_date', now()->toDateString());
            
            // Total sales amount (only from paid orders)
            $totalSales = Order::where('status', '!=', Order::STATUS_CANCELLED)
                ->where('payment_status', 'paid')
                ->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
                ->sum('grand_total');
                
            // Total number of orders
            $totalOrders = Order::whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
                ->count();
                
            // Pending orders count
            $pendingOrders = Order::where('status', Order::STATUS_PENDING)
                ->count();
                
            // Orders by status
            $ordersByStatus = Order::select('status', DB::raw('count(*) as count'))
                ->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
                ->groupBy('status')
                ->get();
                
            // Recent orders
            $recentOrders = Order::with('user')
                ->orderBy('created_at', 'desc')
                ->take(5)
                ->get()
                ->map(function ($order) {
                    return [
                        'id' => $order->id,
                        'order_number' => $order->order_number,
                        'customer_name' => $order->user ? $order->user->name : 'Guest',
                        'total' => $order->grand_total,
                        'status' => $order->status,
                        'payment_status' => $order->payment_status,
                        'created_at' => $order->created_at
                    ];
                });
                
            // Total customers
            $totalCustomers = User::where('role', '!=', 'admin')
                ->count();
                
            // New customers in date range
            $newCustomers = User::where('role', '!=', 'admin')
                ->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
                ->count();
                
            return response()->json([
                'status' => 'success',
                'data' => [
                    'total_sales' => number_format($totalSales, 2),
                    'total_orders' => $totalOrders,
                    'pending_orders' => $pendingOrders,
                    'orders_by_status' => $ordersByStatus,
                    'recent_orders' => $recentOrders,
                    'total_customers' => $totalCustomers,
                    'new_customers' => $newCustomers,
                    'date_range' => [
                        'start_date' => $startDate,
                        'end_date' => $endDate
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting order stats: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get order statistics'
            ], 500);
        }
    }

    /**
     * Export orders as CSV with optional filters
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\StreamedResponse|\Illuminate\Http\JsonResponse
     */
    public function exportOrders(Request $request)
    {
        try {
            // Log request parameters for debugging
            Log::info('Export orders request', [
                'params' => $request->all(),
                'user_agent' => $request->header('User-Agent')
            ]);

            // Get filter parameters
            $status = $request->input('status');
            $paymentMethod = $request->input('payment_method');
            $search = $request->input('search');
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

            Log::info('Export filters', [
                'status' => $status,
                'payment_method' => $paymentMethod,
                'search' => $search,
                'start_date' => $startDate,
                'end_date' => $endDate
            ]);

            // Build query with filters
            try {
                $query = Order::query();
                Log::info('Created base query');
                
                // Eager load relationships with specific columns to reduce memory usage
                $query->with(['user:id,name,email,phone']);
                Log::info('Added user relationship');
                
                // Load items separately to avoid potential issues
                $query->with(['items' => function($query) {
                    $query->select('id', 'order_id', 'product_id', 'product_name', 'quantity', 'unit_price');
                    // Only load product name and id from products to reduce memory usage
                    $query->with('product:id,name');
                }]);
                Log::info('Added items relationship with product');
            } catch (\Exception $e) {
                Log::error('Error creating query', [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                throw $e;
            }

            // Apply filters
            try {
                if ($status && $status !== 'all') {
                    $query->where('status', $status);
                    Log::info('Applied status filter', ['status' => $status]);
                }

                if ($paymentMethod && $paymentMethod !== 'all') {
                    $query->where('payment_method', $paymentMethod);
                    Log::info('Applied payment method filter', ['payment_method' => $paymentMethod]);
                }

                if ($search) {
                    $query->where(function ($q) use ($search) {
                        $q->where('order_number', 'like', "%{$search}%")
                          ->orWhereHas('user', function($userQuery) use ($search) {
                              $userQuery->where('name', 'like', "%{$search}%")
                                       ->orWhere('email', 'like', "%{$search}%")
                                       ->orWhere('phone', 'like', "%{$search}%");
                          });
                    });
                    Log::info('Applied search filter', ['search' => $search]);
                }

                if ($startDate) {
                    $query->whereDate('created_at', '>=', $startDate);
                    Log::info('Applied start date filter', ['start_date' => $startDate]);
                }

                if ($endDate) {
                    $query->whereDate('created_at', '<=', $endDate);
                    Log::info('Applied end date filter', ['end_date' => $endDate]);
                }

                // Order by created_at descending
                $query->orderBy('created_at', 'desc');
                Log::info('Applied ordering');
            } catch (\Exception $e) {
                Log::error('Error applying filters', [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                throw $e;
            }

            // Log the SQL query for debugging
            try {
                Log::info('Export query', ['sql' => $query->toSql(), 'bindings' => $query->getBindings()]);
            } catch (\Exception $e) {
                Log::error('Error generating SQL', [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }

            // Get all orders that match the filters
            try {
                // Use chunk to process large datasets efficiently
                $headers = [
                    'Content-Type' => 'text/csv',
                    'Content-Disposition' => 'attachment; filename="orders-export-' . date('Y-m-d') . '.csv"',
                    'Pragma' => 'no-cache',
                    'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
                    'Expires' => '0',
                ];

                // Create a temporary file to write the CSV data
                $tempFile = tempnam(sys_get_temp_dir(), 'orders_export_');
                $file = fopen($tempFile, 'w');
                
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
                Log::info('Wrote CSV headers to temp file', ['temp_file' => $tempFile]);

                // Process orders in chunks to avoid memory issues
                $totalProcessed = 0;
                $chunkSize = 100; // Process 100 orders at a time
                
                $query->chunk($chunkSize, function($orders) use ($file, &$totalProcessed) {
                    foreach ($orders as $order) {
                        try {
                            // Get user information with fallbacks
                            $customerName = $order->customer_name ?? ($order->user ? $order->user->name : 'Guest');
                            $customerEmail = $order->customer_email ?? ($order->user ? $order->user->email : 'N/A');
                            $customerPhone = $order->customer_phone ?? ($order->user ? $order->user->phone : 'N/A');
                            
                            // Format items as a string with safe fallbacks
                            $items = '';
                            if ($order->items && $order->items->count() > 0) {
                                $items = $order->items->map(function ($item) {
                                    // Safe access to product name
                                    $productName = $item->product_name ?? ($item->product ? $item->product->name : 'Unknown Product');
                                    $quantity = $item->quantity ?? 0;
                                    $price = $item->unit_price ?? 0;
                                    return "{$quantity}x {$productName} (" . number_format($price, 2) . ")";
                                })->implode(', ');
                            }

                            fputcsv($file, [
                                $order->id ?? 'N/A',
                                $order->order_number ?? 'N/A',
                                $customerName,
                                $customerEmail,
                                $customerPhone,
                                number_format($order->grand_total ?? 0, 2),
                                $order->payment_method ?? 'N/A',
                                $order->payment_status ?? 'N/A',
                                $order->status ?? 'N/A',
                                $items,
                                $order->created_at ? $order->created_at->format('Y-m-d H:i:s') : 'N/A',
                                $order->updated_at ? $order->updated_at->format('Y-m-d H:i:s') : 'N/A'
                            ]);
                            $totalProcessed++;
                        } catch (\Exception $e) {
                            Log::error('Error processing order for CSV', [
                                'order_id' => $order->id ?? 'unknown',
                                'error' => $e->getMessage(),
                                'trace' => $e->getTraceAsString()
                            ]);
                            // Continue processing other orders
                        }
                    }
                });
                
                Log::info('Processed orders for CSV', ['count' => $totalProcessed]);
                
                // Close the file
                fclose($file);
                
                // Read the file contents
                $fileContents = file_get_contents($tempFile);
                
                // Delete the temporary file
                unlink($tempFile);
                
                Log::info('Returning CSV response');
                
                // Return the CSV as a downloadable file
                return response($fileContents, 200, $headers);
                
            } catch (\Exception $e) {
                Log::error('Error executing query or generating CSV', [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('Export orders error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
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
