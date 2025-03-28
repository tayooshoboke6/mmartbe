<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;

class SyncProductStock extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'products:sync-stock';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync product stock quantities with their measurements';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting product stock synchronization...');
        
        // Get all products that have measurements
        $products = Product::has('measurements')->get();
        $count = 0;
        
        foreach ($products as $product) {
            $oldStock = $product->stock_quantity;
            $product->syncStockWithMeasurements();
            $newStock = $product->stock_quantity;
            
            if ($oldStock != $newStock) {
                $count++;
                $this->info("Updated product '{$product->name}' stock from {$oldStock} to {$newStock}");
            }
        }
        
        $this->info("Completed! Updated stock for {$count} products.");
        
        return Command::SUCCESS;
    }
}
