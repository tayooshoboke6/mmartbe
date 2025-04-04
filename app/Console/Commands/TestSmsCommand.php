<?php

namespace App\Console\Commands;

use App\Services\TermiiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestSmsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sms:test {phone}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test sending SMS to a specific phone number';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            // Get phone from argument
            $phone = $this->argument('phone');
            
            if (!$phone) {
                $this->error('Phone number is required');
                return 1;
            }
            
            $this->info("Sending test SMS to: {$phone}");
            
            // Log the SMS attempt
            Log::info('Test SMS command called', [
                'phone' => $phone
            ]);
            
            // Create a test message
            $message = "This is a test SMS from M-Mart+ sent at " . now()->format('Y-m-d H:i:s');
            
            // Send the SMS using TermiiService
            $termiiService = new TermiiService();
            $result = $termiiService->sendMessage($phone, null, $message);
            
            if ($result['success']) {
                $this->info('Test SMS sent successfully!');
                $this->info('Response: ' . json_encode($result['data']));
                return 0;
            } else {
                $this->error('Failed to send test SMS: ' . $result['message']);
                return 1;
            }
        } catch (\Exception $e) {
            Log::error('Test SMS command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->error('Failed to send test SMS: ' . $e->getMessage());
            return 1;
        }
    }
}
