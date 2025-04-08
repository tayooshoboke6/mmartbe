<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CleanupSeededOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:cleanup-seeded {--dry-run : Run without making actual changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove seeded orders with future dates while preserving real orders';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting cleanup of seeded orders...');
        
        $dryRun = $this->option('dry-run');
        if ($dryRun) {
            $this->warn('DRY RUN MODE: No actual changes will be made');
        }
        
        // Current date
        $now = Carbon::now();
        
        // Find orders with future dates (these are definitely seeded)
        $futureOrders = Order::where('created_at', '>', $now)->get();
        
        $this->info("Found {$futureOrders->count()} orders with future dates");
        
        if ($futureOrders->count() === 0) {
            $this->info('No future-dated orders to clean up.');
            return 0;
        }
        
        // Process each order
        $deletedCount = 0;
        
        DB::beginTransaction();
        
        try {
            foreach ($futureOrders as $order) {
                $this->info("Processing order #{$order->order_number} (ID: {$order->id})");
                
                if (!$dryRun) {
                    // Delete order items first
                    OrderItem::where('order_id', $order->id)->delete();
                    $this->info("  - Deleted order items");
                    
                    // Delete the order
                    $order->delete();
                    $this->info("  - Deleted order");
                } else {
                    $this->info("  - Would delete order and its items (dry run)");
                }
                
                $deletedCount++;
            }
            
            if (!$dryRun) {
                DB::commit();
                $this->info("Successfully deleted {$deletedCount} seeded orders with future dates");
            } else {
                DB::rollBack();
                $this->info("Dry run completed. Would have deleted {$deletedCount} seeded orders");
            }
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Error during cleanup: {$e->getMessage()}");
            Log::error("Error during seeded orders cleanup", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
        
        return 0;
    }
}
