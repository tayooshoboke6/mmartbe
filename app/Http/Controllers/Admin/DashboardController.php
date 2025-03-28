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
                    ]
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
                    ]
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
