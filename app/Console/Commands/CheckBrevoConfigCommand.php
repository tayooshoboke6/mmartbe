<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

class CheckBrevoConfigCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'brevo:check-config';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check Brevo API configuration';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking Brevo Configuration...');
        
        // Check mail configuration
        $mailDriver = config('mail.default');
        $this->info("Mail Driver: {$mailDriver}");
        
        // Check Brevo API key
        $brevoKey = config('services.brevo.key');
        $brevoApiKey = config('services.brevo.api_key');
        
        if (!empty($brevoKey)) {
            $this->info("Brevo API Key: " . substr($brevoKey, 0, 5) . '...' . substr($brevoKey, -5));
        } else {
            $this->error("Brevo API Key is not set in services.brevo.key");
        }
        
        if (!empty($brevoApiKey)) {
            $this->info("Brevo API Key (api_key): " . substr($brevoApiKey, 0, 5) . '...' . substr($brevoApiKey, -5));
        } else {
            $this->error("Brevo API Key is not set in services.brevo.api_key");
        }
        
        // Check mail from configuration
        $fromAddress = config('mail.from.address');
        $fromName = config('mail.from.name');
        
        $this->info("From Address: {$fromAddress}");
        $this->info("From Name: {$fromName}");
        
        return 0;
    }
}
