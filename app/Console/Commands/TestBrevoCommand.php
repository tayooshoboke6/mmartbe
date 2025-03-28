<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use SendinBlue\Client\Api\TransactionalEmailsApi;
use SendinBlue\Client\Configuration;
use SendinBlue\Client\Model\SendSmtpEmail;
use SendinBlue\Client\Model\SendSmtpEmailSender;
use SendinBlue\Client\Model\SendSmtpEmailTo;

class TestBrevoCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'brevo:test {email}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test Brevo API directly without Laravel Mail';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->argument('email');
        $this->info("Testing Brevo API with email: {$email}");
        
        try {
            // Get API key
            $apiKey = config('services.brevo.key');
            if (empty($apiKey)) {
                $this->error("Brevo API key is not set in config");
                return 1;
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
                'subject' => 'Test Email from M-Mart+ via Brevo API',
                'htmlContent' => '<html><body><h1>Test Email</h1><p>This is a test email from M-Mart+ sent directly via the Brevo API.</p><p>Time: ' . date('Y-m-d H:i:s') . '</p></body></html>',
                'textContent' => 'This is a test email from M-Mart+ sent directly via the Brevo API. Time: ' . date('Y-m-d H:i:s'),
            ]);
            
            $this->info("Sending email...");
            
            // Send the email
            $result = $apiInstance->sendTransacEmail($sendSmtpEmail);
            
            $this->info("Email sent successfully!");
            $this->info("Message ID: " . ($result->getMessageId() ?? 'No message ID'));
            
            // Log the result
            Log::info('TestBrevoCommand email sent successfully', [
                'result' => json_encode($result),
                'message_id' => $result->getMessageId() ?? 'No message ID',
            ]);
            
            return 0;
        } catch (\Exception $e) {
            $this->error("Failed to send email: " . $e->getMessage());
            
            // Log the error
            Log::error('TestBrevoCommand failed to send email', [
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_class' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
            
            return 1;
        }
    }
}
