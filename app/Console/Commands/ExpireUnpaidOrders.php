<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductMeasurement;
use App\Events\OrderExpired;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ExpireUnpaidOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:expire-unpaid {--hours=24 : Hours after which unpaid orders should expire}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire unpaid orders that have been pending for too long and return stock to inventory';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $hours = $this->option('hours');
        $cutoffTime = Carbon::now()->subHours($hours);
        
        $this->info("Finding orders that have been pending payment for more than {$hours} hours...");
        
        // Find orders that are pending payment and were created before the cutoff time
        $orders = Order::where('payment_status', Order::PAYMENT_PENDING)
            ->where('status', Order::STATUS_PENDING)
            ->where('created_at', '<', $cutoffTime)
            ->whereNull('expired_at')
            ->get();
        
        $count = $orders->count();
        $this->info("Found {$count} orders to expire.");
        
        if ($count === 0) {
            return 0;
        }
        
        $successCount = 0;
        $errorCount = 0;
        
        foreach ($orders as $order) {
            DB::beginTransaction();
            
            try {
                // Mark the order as expired
                $order->status = Order::STATUS_EXPIRED;
                $order->expired_at = Carbon::now();
                $order->save();
                
                // Return stock to inventory
                foreach ($order->items as $item) {
                    if ($item->product_measurement_id) {
                        $measurement = ProductMeasurement::find($item->product_measurement_id);
                        if ($measurement) {
                            $measurement->increment('stock_quantity', $item->quantity);
                            $this->info("Returned {$item->quantity} units to measurement stock for product {$item->product_id}, measurement {$item->product_measurement_id}");
                        }
                    } else {
                        $product = Product::find($item->product_id);
                        if ($product) {
                            $product->increment('stock_quantity', $item->quantity);
                            $this->info("Returned {$item->quantity} units to stock for product {$item->product_id}");
                        }
                    }
                }
                
                // If coupon was used, decrement usage count
                if ($order->coupon_id) {
                    $coupon = $order->coupon;
                    if ($coupon && $coupon->used_count > 0) {
                        $coupon->decrement('used_count');
                        $this->info("Decremented coupon usage for coupon {$order->coupon_id}");
                    }
                }
                
                // Dispatch event for any listeners
                event(new OrderExpired($order));
                
                DB::commit();
                $successCount++;
                $this->info("Successfully expired order #{$order->order_number}");
            } catch (\Exception $e) {
                DB::rollBack();
                $errorCount++;
                $this->error("Error expiring order #{$order->order_number}: {$e->getMessage()}");
                Log::error("Error expiring order", [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }
        
        $this->info("Completed expiring orders. Success: {$successCount}, Errors: {$errorCount}");
        
        return 0;
    }
}
