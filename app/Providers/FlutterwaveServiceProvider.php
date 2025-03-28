<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Log;
use Flutterwave\Helper\Config;
use Dotenv\Dotenv;

class FlutterwaveServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        try {
            // Load Flutterwave-specific environment variables
            $flutterwaveDotenv = Dotenv::createImmutable(base_path(), '.env.flutterwave');
            $flutterwaveDotenv->load();
            
            // Get config from environment variables (fallback to our custom variables if needed)
            $publicKey = $_ENV['PUBLIC_KEY'] ?? env('FLUTTERWAVE_PUBLIC_KEY');
            $secretKey = $_ENV['SECRET_KEY'] ?? env('FLUTTERWAVE_SECRET_KEY');
            $encryptionKey = $_ENV['ENCRYPTION_KEY'] ?? env('FLUTTERWAVE_ENCRYPTION_KEY');
            $environment = $_ENV['ENV'] ?? (env('APP_ENV') === 'production' ? 'live' : 'staging');

            // Log the configuration (without sensitive keys)
            Log::info('Flutterwave configuration loaded', [
                'environment' => $environment,
                'public_key_exists' => !empty($publicKey),
                'secret_key_exists' => !empty($secretKey),
                'encryption_key_exists' => !empty($encryptionKey),
            ]);

            // Bootstrap Flutterwave with our config
            \Flutterwave\Flutterwave::bootstrap();
            
            // Set up Flutterwave configuration using the helper
            Config::setUp(
                $secretKey,
                $publicKey,
                $encryptionKey,
                $environment
            );
            
        } catch (\Exception $e) {
            Log::error('Failed to bootstrap Flutterwave', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
