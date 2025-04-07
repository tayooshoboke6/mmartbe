<?php

namespace App\Listeners;

use App\Events\OrderExpired;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RecordOrderExpirationAnalytics implements ShouldQueue
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(OrderExpired $event): void
    {
        $order = $event->order;
        
        try {
            // Record analytics data for the expired order
            DB::table('order_analytics')->updateOrInsert(
                ['date' => Carbon::now()->format('Y-m-d')],
                [
                    'expired_orders_count' => DB::raw('expired_orders_count + 1'),
                    'expired_orders_value' => DB::raw('expired_orders_value + ' . $order->grand_total),
                    'updated_at' => Carbon::now(),
                ]
            );
            
            // Record user abandonment data
            if ($order->user_id) {
                DB::table('user_analytics')->updateOrInsert(
                    ['user_id' => $order->user_id],
                    [
                        'abandoned_orders_count' => DB::raw('abandoned_orders_count + 1'),
                        'abandoned_orders_value' => DB::raw('abandoned_orders_value + ' . $order->grand_total),
                        'updated_at' => Carbon::now(),
                    ]
                );
            }
            
            Log::info('Recorded order expiration analytics', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'user_id' => $order->user_id,
                'grand_total' => $order->grand_total
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to record order expiration analytics', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
