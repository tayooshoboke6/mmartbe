<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExpiredOrdersController extends Controller
{
    /**
     * Display a listing of expired orders.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        try {
            $query = Order::where('status', Order::STATUS_EXPIRED)
                ->with(['user', 'items.product']);

            // Apply date filters if provided
          if ($request->has('start_date') && $request->has('end_date')) {
          $startDate = Carbon::parse($request->start_date)->startOfDay();
          $endDate = Carbon::parse($request->end_date)->endOfDay();
          $query->whereBetween('expired_at', [$startDate, $endDate]);
      } else {
          // If no dates provided, default to today
          $today = Carbon::today();
          $query->whereDate('expired_at', $today);
      }


            // Apply search filter if provided
            if ($request->has('search') && !empty($request->search)) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('order_number', 'like', "%{$search}%")
                      ->orWhereHas('user', function ($userQuery) use ($search) {
                          $userQuery->where('name', 'like', "%{$search}%")
                                   ->orWhere('email', 'like', "%{$search}%");
                      });
                });
            }

            // Apply sorting
            $sortField = $request->input('sort_field', 'expired_at');
            $sortDirection = $request->input('sort_direction', 'desc');
            $query->orderBy($sortField, $sortDirection);

            // Paginate the results
            $perPage = $request->input('per_page', 15);
            $expiredOrders = $query->paginate($perPage);

            // Calculate summary statistics
            $totalExpiredOrders = Order::where('status', Order::STATUS_EXPIRED)->count();
            $totalExpiredValue = Order::where('status', Order::STATUS_EXPIRED)->sum('grand_total');
            $averageExpiredValue = $totalExpiredOrders > 0 ? $totalExpiredValue / $totalExpiredOrders : 0;

            // Get top 5 products in expired orders
            $topExpiredProducts = DB::table('order_items')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->join('products', 'order_items.product_id', '=', 'products.id')
                ->select(
                    'products.id',
                    'products.name',
                    DB::raw('SUM(order_items.quantity) as total_quantity'),
                    DB::raw('COUNT(DISTINCT orders.id) as order_count')
                )
                ->where('orders.status', Order::STATUS_EXPIRED)
                ->groupBy('products.id', 'products.name')
                ->orderBy('total_quantity', 'desc')
                ->limit(5)
                ->get();

            // Get users with most expired orders
            $usersWithMostExpiredOrders = DB::table('orders')
                ->join('users', 'orders.user_id', '=', 'users.id')
                ->select(
                    'users.id',
                    'users.name',
                    'users.email',
                    DB::raw('COUNT(*) as expired_order_count'),
                    DB::raw('SUM(orders.grand_total) as total_value')
                )
                ->where('orders.status', Order::STATUS_EXPIRED)
                ->groupBy('users.id', 'users.name', 'users.email')
                ->orderBy('expired_order_count', 'desc')
                ->limit(5)
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'expired_orders' => $expiredOrders,
                    'summary' => [
                        'total_expired_orders' => $totalExpiredOrders,
                        'total_expired_value' => number_format($totalExpiredValue, 2),
                        'average_expired_value' => number_format($averageExpiredValue, 2),
                    ],
                    'top_expired_products' => $topExpiredProducts,
                    'users_with_most_expired_orders' => $usersWithMostExpiredOrders
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching expired orders: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch expired orders: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get expired order analytics.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function getAnalytics(Request $request)
    {
        try {
            // Get date range
            $endDate = Carbon::now();
            $startDate = Carbon::now()->subDays(30);

            if ($request->has('start_date') && $request->has('end_date')) {
                $startDate = Carbon::parse($request->start_date);
                $endDate = Carbon::parse($request->end_date);
            }

            // Get daily expired orders for the date range
            $dailyExpiredOrders = Order::where('status', Order::STATUS_EXPIRED)
                ->whereBetween('expired_at', [$startDate, $endDate])
                ->select(
                    DB::raw('DATE(expired_at) as date'),
                    DB::raw('COUNT(*) as count'),
                    DB::raw('SUM(grand_total) as value')
                )
                ->groupBy('date')
                ->orderBy('date')
                ->get()
                ->map(function ($item) {
                    return [
                        'date' => Carbon::parse($item->date)->format('M d, Y'),
                        'count' => $item->count,
                        'value' => $item->value
                    ];
                });

            // Get expiration rate over time (expired orders / total orders per day)
            $expirationRateOverTime = [];
            $dateRange = [];
            $currentDate = clone $startDate;

            while ($currentDate->lte($endDate)) {
                $dateStr = $currentDate->format('Y-m-d');
                $dateRange[] = $currentDate->format('M d, Y');

                $totalOrdersForDay = Order::whereDate('created_at', $dateStr)->count();
                $expiredOrdersForDay = Order::where('status', Order::STATUS_EXPIRED)
                    ->whereDate('expired_at', $dateStr)
                    ->count();

                $rate = $totalOrdersForDay > 0 ? ($expiredOrdersForDay / $totalOrdersForDay) * 100 : 0;
                $expirationRateOverTime[] = [
                    'date' => $currentDate->format('M d, Y'),
                    'rate' => round($rate, 2)
                ];

                $currentDate->addDay();
            }

            // Get expiration by product category
            $expirationByCategory = DB::table('orders')
                ->join('order_items', 'orders.id', '=', 'order_items.order_id')
                ->join('products', 'order_items.product_id', '=', 'products.id')
                ->join('categories', 'products.category_id', '=', 'categories.id')
                ->select(
                    'categories.id',
                    'categories.name',
                    DB::raw('COUNT(DISTINCT orders.id) as expired_order_count'),
                    DB::raw('SUM(order_items.quantity) as total_quantity')
                )
                ->where('orders.status', Order::STATUS_EXPIRED)
                ->whereBetween('orders.expired_at', [$startDate, $endDate])
                ->groupBy('categories.id', 'categories.name')
                ->orderBy('expired_order_count', 'desc')
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'daily_expired_orders' => $dailyExpiredOrders,
                    'expiration_rate_over_time' => $expirationRateOverTime,
                    'expiration_by_category' => $expirationByCategory,
                    'date_range' => [
                        'start_date' => $startDate->format('Y-m-d'),
                        'end_date' => $endDate->format('Y-m-d')
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching expired order analytics: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch expired order analytics: ' . $e->getMessage()
            ], 500);
        }
    }
}
