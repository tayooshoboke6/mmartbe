<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    /**
     * Get hourly data for the last 24 hours
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getHourlyData(Request $request)
    {
        try {
            // Get date range
            $endDate = Carbon::now();
            $startDate = Carbon::now()->subHours(24);
            
            // Get hourly order counts and revenue
            $hourlyData = Order::select(
                    DB::raw('HOUR(created_at) as hour'),
                    DB::raw('COUNT(*) as order_count'),
                    DB::raw('SUM(grand_total) as revenue')
                )
                ->whereBetween('created_at', [$startDate, $endDate])
                ->groupBy(DB::raw('HOUR(created_at)'))
                ->orderBy(DB::raw('HOUR(created_at)'))
                ->get()
                ->keyBy('hour');
            
            // Format data for all 24 hours (including zeros)
            $formattedData = [];
            for ($i = 0; $i < 24; $i++) {
                $hour = ($endDate->hour - $i + 24) % 24; // Calculate hour in reverse from current hour
                $hourLabel = sprintf('%02d:00', $hour);
                
                $formattedData[] = [
                    'hour' => $hourLabel,
                    'order_count' => $hourlyData->has($hour) ? $hourlyData[$hour]->order_count : 0,
                    'revenue' => $hourlyData->has($hour) ? $hourlyData[$hour]->revenue : 0
                ];
            }
            
            // Get hourly expired orders
            $hourlyExpirations = Order::select(
                    DB::raw('HOUR(expired_at) as hour'),
                    DB::raw('COUNT(*) as count')
                )
                ->where('status', Order::STATUS_EXPIRED)
                ->whereBetween('expired_at', [$startDate, $endDate])
                ->groupBy(DB::raw('HOUR(expired_at)'))
                ->orderBy(DB::raw('HOUR(expired_at)'))
                ->get()
                ->keyBy('hour');
                
            // Format expiration data for all 24 hours
            $expirationData = [];
            for ($i = 0; $i < 24; $i++) {
                $hour = ($endDate->hour - $i + 24) % 24;
                $hourLabel = sprintf('%02d:00', $hour);
                
                $expirationData[] = [
                    'hour' => $hourLabel,
                    'count' => $hourlyExpirations->has($hour) ? $hourlyExpirations[$hour]->count : 0
                ];
            }
            
            // Reverse arrays to show chronological order
            $formattedData = array_reverse($formattedData);
            $expirationData = array_reverse($expirationData);
            
            return response()->json([
                'status' => 'success',
                'data' => [
                    'hourly_orders' => $formattedData,
                    'hourly_expirations' => $expirationData,
                    'date_range' => [
                        'start_date' => $startDate->format('Y-m-d H:i:s'),
                        'end_date' => $endDate->format('Y-m-d H:i:s')
                    ]
                ]
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Error getting hourly data: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get hourly data'
            ], 500);
        }
    }
    
    /**
     * Get dashboard statistics
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getStats()
    {
        // Get date range for filtering
        $endDate = Carbon::now();
        $startDate = Carbon::now()->subDays(30);
        
        try {
            // Get total sales (only from paid orders)
            $totalSales = Order::where('payment_status', 'paid')
                ->where('status', '!=', 'cancelled')
                ->sum('grand_total');
                    
            // Get total orders
            $totalOrders = Order::count();
            
            // Get pending orders
            $pendingOrders = Order::where('status', 'pending')->count();

            // Get orders by status
            $ordersByStatus = Order::select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->get()
                ->map(function ($item) {
                    return [
                        'status' => $item->status,
                        'count' => $item->count
                    ];
                });

            // Get recent orders
            $recentOrders = Order::with('user')
                ->orderBy('created_at', 'desc')
                ->take(5)
                ->get()
                ->map(function ($order) {
                    return [
                        'id' => $order->id,
                        'order_number' => $order->order_number,
                        'customer_name' => $order->user->name,
                        'total' => $order->grand_total,
                        'status' => $order->status,
                        'payment_status' => $order->payment_status,
                        'created_at' => $order->created_at
                    ];
                });

            // Get expired orders count and value
            $expiredOrdersCount = Order::where('status', Order::STATUS_EXPIRED)->count();
            $expiredOrdersValue = Order::where('status', Order::STATUS_EXPIRED)->sum('grand_total');
            
            // Get expiration rate (expired orders as percentage of total)
            $expirationRate = $totalOrders > 0 ? round(($expiredOrdersCount / $totalOrders) * 100, 2) : 0;
            
            // Get recent expired orders
            $recentExpiredOrders = Order::with('user')
                ->where('status', Order::STATUS_EXPIRED)
                ->orderBy('expired_at', 'desc')
                ->take(5)
                ->get()
                ->map(function ($order) {
                    return [
                        'id' => $order->id,
                        'order_number' => $order->order_number,
                        'customer_name' => $order->user ? $order->user->name : 'Guest',
                        'total' => $order->grand_total,
                        'created_at' => $order->created_at,
                        'expired_at' => $order->expired_at
                    ];
                });
                
            // Get daily expiration data for the last 7 days
            $last7Days = $this->getLast7Days();
            $dailyExpirations = [];
            
            for ($i = 6; $i >= 0; $i--) {
                $date = Carbon::now()->subDays($i);
                $count = Order::where('status', Order::STATUS_EXPIRED)
                    ->whereDate('expired_at', $date->format('Y-m-d'))
                    ->count();
                
                $dailyExpirations[] = [
                    'date' => $date->format('M d'),
                    'count' => $count
                ];
            }
            
            return response()->json([
                'status' => 'success',
                'data' => [
                    'total_sales' => number_format($totalSales, 2),
                    'total_orders' => $totalOrders,
                    'pending_orders' => $pendingOrders,
                    'orders_by_status' => $ordersByStatus,
                    'recent_orders' => $recentOrders,
                    'total_customers' => User::where('role', 'customer')->count(),
                    'new_customers' => User::where('role', 'customer')
                        ->whereBetween('created_at', [$startDate, $endDate])
                        ->count(),
                    'date_range' => [
                        'start_date' => $startDate->format('Y-m-d'),
                        'end_date' => $endDate->format('Y-m-d')
                    ],
                    // Expiration metrics
                    'expired_orders_count' => $expiredOrdersCount,
                    'expired_orders_value' => number_format($expiredOrdersValue, 2),
                    'expiration_rate' => $expirationRate,
                    'recent_expired_orders' => $recentExpiredOrders,
                    'daily_expirations' => $dailyExpirations
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('Error getting dashboard stats: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get dashboard statistics'
            ], 500);
        }
    }

    /**
     * Get the last 7 days as formatted strings
     *
     * @return array
     */
    private function getLast7Days()
    {
        $days = [];
        for ($i = 6; $i >= 0; $i--) {
            $days[] = Carbon::now()->subDays($i)->format('M d');
        }
        return $days;
    }

    /**
     * Get order statistics
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getOrderStats()
    {
        try {
            // Get total sales (only from paid orders)
            $totalSales = Order::where('payment_status', 'paid')
                ->where('status', '!=', 'cancelled')
                ->sum('grand_total');

            // Get total orders
            $totalOrders = Order::count();

            // Get pending orders
            $pendingOrders = Order::where('status', 'pending')->count();

            // Get orders by status
            $ordersByStatus = Order::select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->get()
                ->map(function ($item) {
                    return [
                        'status' => $item->status,
                        'count' => $item->count
                    ];
                });

            // Get recent orders
            $recentOrders = Order::with('user')
                ->orderBy('created_at', 'desc')
                ->take(5)
                ->get()
                ->map(function ($order) {
                    return [
                        'id' => $order->id,
                        'order_number' => $order->order_number,
                        'customer_name' => $order->user->name,
                        'total' => $order->grand_total,
                        'status' => $order->status,
                        'payment_status' => $order->payment_status,
                        'created_at' => $order->created_at
                    ];
                });

            // Get expired orders count and value
            $expiredOrdersCount = Order::where('status', Order::STATUS_EXPIRED)->count();
            $expiredOrdersValue = Order::where('status', Order::STATUS_EXPIRED)->sum('grand_total');
            
            // Get expiration rate (expired orders as percentage of total)
            $expirationRate = $totalOrders > 0 ? round(($expiredOrdersCount / $totalOrders) * 100, 2) : 0;
            
            // Get recent expired orders
            $recentExpiredOrders = Order::with('user')
                ->where('status', Order::STATUS_EXPIRED)
                ->orderBy('expired_at', 'desc')
                ->take(5)
                ->get()
                ->map(function ($order) {
                    return [
                        'id' => $order->id,
                        'order_number' => $order->order_number,
                        'customer_name' => $order->user ? $order->user->name : 'Guest',
                        'total' => $order->grand_total,
                        'created_at' => $order->created_at,
                        'expired_at' => $order->expired_at
                    ];
                });
                
            // Get daily expiration data for the last 7 days
            $last7Days = $this->getLast7Days();
            $dailyExpirations = [];
            
            for ($i = 6; $i >= 0; $i--) {
                $date = Carbon::now()->subDays($i);
                $count = Order::where('status', Order::STATUS_EXPIRED)
                    ->whereDate('expired_at', $date->format('Y-m-d'))
                    ->count();
                
                $dailyExpirations[] = [
                    'date' => $date->format('M d'),
                    'count' => $count
                ];
            }
            
            return response()->json([
                'status' => 'success',
                'data' => [
                    'total_sales' => number_format($totalSales, 2),
                    'total_orders' => $totalOrders,
                    'pending_orders' => $pendingOrders,
                    'orders_by_status' => $ordersByStatus,
                    'recent_orders' => $recentOrders,
                    'total_customers' => User::where('role', 'customer')->count(),
                    'new_customers' => User::where('role', 'customer')
                        ->whereBetween('created_at', [Carbon::now()->subDays(30), Carbon::now()])
                        ->count(),
                    'date_range' => [
                        'start_date' => Carbon::now()->subDays(30)->format('Y-m-d'),
                        'end_date' => Carbon::now()->format('Y-m-d')
                    ],
                    // Expiration metrics
                    'expired_orders_count' => $expiredOrdersCount,
                    'expired_orders_value' => number_format($expiredOrdersValue, 2),
                    'expiration_rate' => $expirationRate,
                    'recent_expired_orders' => $recentExpiredOrders,
                    'daily_expirations' => $dailyExpirations
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('Error getting order stats: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get order statistics'
            ], 500);
        }
    }
}
