<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class TestEmail extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email:test {email}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test email functionality by sending a test email';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $email = $this->argument('email');
        $this->info("Sending test email to: {$email}");
        
        try {
            Mail::raw('This is a test email from MMart application to verify email functionality.', function ($message) use ($email) {
                $message->to($email)
                    ->subject('MMart Test Email');
            });
            
            $this->info('Test email sent successfully!');
            Log::info("Test email sent to {$email}");
            return 0;
        } catch (\Exception $e) {
            $this->error('Failed to send test email: ' . $e->getMessage());
            Log::error('Test email failed: ' . $e->getMessage());
            return 1;
        }
    }
}
