<?php

namespace App\Listeners;

use App\Events\OrderExpired;
use App\Models\Order;
use App\Notifications\OrderExpirationNotification;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendOrderExpirationNotification implements ShouldQueue
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
        
        // Only send notification if it hasn't been sent already
        if ($order->expiration_notified_at) {
            Log::info('Order expiration notification already sent', [
                'order_id' => $order->id,
                'order_number' => $order->order_number
            ]);
            return;
        }
        
        try {
            // Get the user who placed the order
            $user = $order->user;
            
            if ($user) {
                // Send notification to the user
                $user->notify(new OrderExpirationNotification($order));
                
                // Mark notification as sent
                $order->expiration_notified_at = Carbon::now();
                $order->save();
                
                Log::info('Order expiration notification sent', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'user_id' => $user->id,
                    'user_email' => $user->email
                ]);
            } else {
                Log::warning('Cannot send order expiration notification - user not found', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send order expiration notification', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
