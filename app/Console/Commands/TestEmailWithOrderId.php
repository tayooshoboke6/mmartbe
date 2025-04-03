<?php

namespace App\Console\Commands;

use App\Mail\OrderConfirmationMail;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class TestEmailWithOrderId extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email:test-with-order {email} {order_id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test sending order confirmation email with a specific order ID';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            // Get email from argument
            $email = $this->argument('email');
            $orderId = $this->argument('order_id');
            
            // Get the specified order
            $order = Order::with(['items', 'user'])->findOrFail($orderId);
            
            $this->info("Sending test email to: {$email}");
            $this->info("Using order #: {$order->order_number}");
            
            // Log the email attempt
            Log::info('Test email with order ID command called', [
                'email' => $email,
                'order_id' => $order->id,
                'order_number' => $order->order_number
            ]);
            
            // Send the email
            Mail::to($email)->send(new OrderConfirmationMail($order));
            
            $this->info('Test email sent successfully!');
            return 0;
        } catch (\Exception $e) {
            Log::error('Test email with order ID command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->error('Failed to send test email: ' . $e->getMessage());
            return 1;
        }
    }
}
