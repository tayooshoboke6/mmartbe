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
        CleanupSeededOrders::class,
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Run the order expiration command every 10 minutes
        $schedule->command('orders:expire-unpaid --hours=0.5')
            ->everyTenMinutes();

        // Run the order expiration command daily at midnight
        $schedule->command('orders:expire-unpaid')
            ->daily()
            ->withoutOverlapping();
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
