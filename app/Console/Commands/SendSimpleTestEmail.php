<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendSimpleTestEmail extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email:simple-test {email}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send a simple test email';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->argument('email');
        $this->info("Sending simple test email to: {$email}");
        
        try {
            Mail::raw('This is a simple test email from M-Mart+ at ' . now(), function($message) use ($email) {
                $message->to($email)
                        ->subject('M-Mart+ Simple Test Email - ' . now()->format('H:i:s'));
            });
            
            $this->info('Simple test email sent successfully!');
            return 0;
        } catch (\Exception $e) {
            $this->error('Failed to send simple test email: ' . $e->getMessage());
            return 1;
        }
    }
}
