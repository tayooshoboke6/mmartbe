<?php

namespace App\Console\Commands;

use App\Mail\OrderConfirmationMail;
use App\Models\Order;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use SendinBlue\Client\Api\TransactionalEmailsApi;
use SendinBlue\Client\Configuration;
use SendinBlue\Client\Model\SendSmtpEmail;
use SendinBlue\Client\Model\SendSmtpEmailSender;
use SendinBlue\Client\Model\SendSmtpEmailTo;

class DebugEmailSystem extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email:debug {email} {--mode=all : Mode to run (all, config, laravel, brevo, order)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Debug the email system with detailed output';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->argument('email');
        $mode = $this->option('mode');
        
        $this->info("===== EMAIL SYSTEM DEBUGGING =====");
        $this->info("Target email: {$email}");
        $this->info("Mode: {$mode}");
        $this->info("Time: " . now()->format('Y-m-d H:i:s'));
        $this->info("==============================");
        
        // Check configuration
        if ($mode === 'all' || $mode === 'config') {
            $this->checkConfiguration();
        }
        
        // Test Laravel Mail
        if ($mode === 'all' || $mode === 'laravel') {
            $this->testLaravelMail($email);
        }
        
        // Test Brevo API directly
        if ($mode === 'all' || $mode === 'brevo') {
            $this->testBrevoApi($email);
        }
        
        // Test Order Confirmation Email
        if ($mode === 'all' || $mode === 'order') {
            $this->testOrderConfirmationEmail($email);
        }
        
        $this->info("===== DEBUGGING COMPLETE =====");
    }
    
    /**
     * Check email configuration
     */
    private function checkConfiguration()
    {
        $this->info("\n----- CONFIGURATION CHECK -----");
        
        // Check mail driver
        $mailDriver = config('mail.default');
        $this->info("Mail Driver: {$mailDriver}");
        
        // Check Brevo configuration
        $brevoKey = config('services.brevo.key');
        $brevoApiKey = env('BREVO_API_KEY');
        
        $this->info("Brevo Key from config: " . ($brevoKey ? substr($brevoKey, 0, 5) . '...' . substr($brevoKey, -5) : 'NOT SET'));
        $this->info("Brevo Key from env: " . ($brevoApiKey ? substr($brevoApiKey, 0, 5) . '...' . substr($brevoApiKey, -5) : 'NOT SET'));
        
        // Check mail from configuration
        $fromAddress = config('mail.from.address');
        $fromName = config('mail.from.name');
        
        $this->info("From Address: {$fromAddress}");
        $this->info("From Name: {$fromName}");
        
        // Check if SendinBlue SDK is installed
        $this->info("SendinBlue SDK: " . (class_exists('SendinBlue\Client\Configuration') ? 'INSTALLED' : 'NOT INSTALLED'));
        
        // Check if BrevoTransport is registered
        $this->info("BrevoTransport: " . (class_exists('App\Mail\Transport\BrevoTransport') ? 'REGISTERED' : 'NOT REGISTERED'));
    }
    
    /**
     * Test Laravel Mail
     */
    private function testLaravelMail($email)
    {
        $this->info("\n----- TESTING LARAVEL MAIL -----");
        
        try {
            $this->info("Sending simple test email via Laravel Mail...");
            
            Mail::raw('This is a test email from Laravel Mail at ' . now()->format('Y-m-d H:i:s'), function($message) use ($email) {
                $message->to($email)
                        ->subject('Laravel Mail Test - ' . now()->format('H:i:s'));
            });
            
            $this->info("Laravel Mail test completed without errors");
        } catch (\Exception $e) {
            $this->error("Laravel Mail test failed: " . $e->getMessage());
            $this->error("Error class: " . get_class($e));
            $this->error("Error code: " . $e->getCode());
            $this->error("Stack trace: " . $e->getTraceAsString());
        }
    }
    
    /**
     * Test Brevo API directly
     */
    private function testBrevoApi($email)
    {
        $this->info("\n----- TESTING BREVO API DIRECTLY -----");
        
        try {
            // Get API key
            $apiKey = env('BREVO_API_KEY') ?: config('services.brevo.key');
            if (empty($apiKey)) {
                $this->error("Brevo API key is not set");
                return;
            }
            
            $this->info("API Key length: " . strlen($apiKey));
            $this->info("API Key starts with: " . substr($apiKey, 0, 5));
            
            // Configure API client
            $config = Configuration::getDefaultConfiguration()->setApiKey('api-key', $apiKey);
            $apiInstance = new TransactionalEmailsApi(
                new \GuzzleHttp\Client(),
                $config
            );
            
            // Create sender
            $sender = new SendSmtpEmailSender([
                'name' => config('mail.from.name', 'M-Mart+ Support'),
                'email' => config('mail.from.address', 'noreply@mmartplus.com'),
            ]);
            
            // Create recipient
            $recipient = new SendSmtpEmailTo([
                'email' => $email,
                'name' => 'Test Recipient',
            ]);
            
            // Create email
            $sendSmtpEmail = new SendSmtpEmail([
                'sender' => $sender,
                'to' => [$recipient],
                'subject' => 'Brevo API Direct Test - ' . now()->format('H:i:s'),
                'htmlContent' => '<html><body><h1>Brevo API Test</h1><p>This is a test email sent directly via the Brevo API.</p><p>Time: ' . now()->format('Y-m-d H:i:s') . '</p></body></html>',
                'textContent' => 'This is a test email sent directly via the Brevo API. Time: ' . now()->format('Y-m-d H:i:s'),
            ]);
            
            $this->info("Sending email via Brevo API...");
            
            // Send the email
            $result = $apiInstance->sendTransacEmail($sendSmtpEmail);
            
            $this->info("Brevo API test completed successfully");
            $this->info("Message ID: " . ($result->getMessageId() ?? 'No message ID'));
            
            // Log the result
            Log::info('DebugEmailSystem: Brevo API test successful', [
                'result' => json_encode($result),
                'message_id' => $result->getMessageId() ?? 'No message ID',
            ]);
        } catch (\Exception $e) {
            $this->error("Brevo API test failed: " . $e->getMessage());
            $this->error("Error class: " . get_class($e));
            $this->error("Error code: " . $e->getCode());
            $this->error("Stack trace: " . $e->getTraceAsString());
            
            // Log the error
            Log::error('DebugEmailSystem: Brevo API test failed', [
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_class' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    
    /**
     * Test Order Confirmation Email
     */
    private function testOrderConfirmationEmail($email)
    {
        $this->info("\n----- TESTING ORDER CONFIRMATION EMAIL -----");
        
        try {
            // Get the latest order
            $order = Order::with(['items', 'user'])->latest()->first();
            if (!$order) {
                $this->error("No orders found to test email");
                return;
            }
            
            $this->info("Using order #: {$order->order_number}");
            $this->info("Order date: {$order->created_at}");
            $this->info("Order items: " . $order->items->count());
            
            // Create a temporary user with the test email
            $originalUserEmail = $order->user->email;
            $order->user->email = $email;
            
            $this->info("Sending order confirmation email to: {$email}");
            
            // Send the email
            Mail::to($email)->send(new OrderConfirmationMail($order));
            
            // Restore the original email
            $order->user->email = $originalUserEmail;
            
            $this->info("Order confirmation email sent successfully");
        } catch (\Exception $e) {
            $this->error("Order confirmation email test failed: " . $e->getMessage());
            $this->error("Error class: " . get_class($e));
            $this->error("Error code: " . $e->getCode());
            $this->error("Stack trace: " . $e->getTraceAsString());
            
            // Log the error
            Log::error('DebugEmailSystem: Order confirmation email test failed', [
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_class' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
