<?php

namespace App\Console\Commands;

use App\Mail\OrderConfirmationMail;
use App\Models\Order;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class TestEmailCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email:test {email?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test sending order confirmation email';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            // Get email from argument or use the first user
            $email = $this->argument('email');
            
            if (!$email) {
                $user = User::first();
                if (!$user) {
                    $this->error('No users found to test email');
                    return 1;
                }
                $email = $user->email;
            }
            
            // Get the latest order
            $order = Order::with(['items', 'user'])->latest()->first();
            if (!$order) {
                $this->error('No orders found to test email');
                return 1;
            }
            
            $this->info("Sending test email to: {$email}");
            $this->info("Using order #: {$order->order_number}");
            
            // Log the email attempt
            Log::info('Test email command called', [
                'email' => $email,
                'order_id' => $order->id,
                'order_number' => $order->order_number
            ]);
            
            // Send the email
            Mail::to($email)->send(new OrderConfirmationMail($order));
            
            $this->info('Test email sent successfully!');
            return 0;
        } catch (\Exception $e) {
            Log::error('Test email command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->error('Failed to send test email: ' . $e->getMessage());
            return 1;
        }
    }
}
