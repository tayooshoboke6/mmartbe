<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Console\Commands\ExpireUnpaidOrders;
use App\Console\Commands\CleanupSeededOrders;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        ExpireUnpaidOrders::class,
        
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Run the order expiration command every 10 minutes to check for orders older than 30 minutes
        $schedule->command('orders:expire-unpaid --hours=0.5')
            ->everyTenMinutes()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/order-expiration.log'));
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
