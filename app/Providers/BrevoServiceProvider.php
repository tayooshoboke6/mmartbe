<?php

namespace App\Providers;

use App\Mail\Transport\BrevoTransport;
use Illuminate\Mail\MailManager;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Notification;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\Mail;

class BrevoServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Register the Brevo transport
        $this->app->afterResolving(MailManager::class, function (MailManager $manager) {
            $manager->extend('brevo', function () {
                $apiKey = env('BREVO_API_KEY') ?: config('services.brevo.key');
                
                // Log the API key being used
                \Illuminate\Support\Facades\Log::info('BrevoServiceProvider: Initializing transport', [
                    'api_key_exists' => !empty($apiKey),
                    'api_key_length' => strlen($apiKey),
                    'api_key_starts_with' => substr($apiKey, 0, 5)
                ]);
                
                return new BrevoTransport($apiKey);
            });
        });

        // Override the mail channel for notifications to ensure recipient names
        $this->app->bind(MailChannel::class, function ($app) {
            return new class($app->make('mailer')) extends MailChannel {
                protected function buildMessage($message, $notifiable, $notification)
                {
                    // Get the original message
                    parent::buildMessage($message, $notifiable, $notification);
                    
                    // Get the email address
                    $email = $notifiable->routeNotificationFor('mail', $notification);
                    
                    // Get a name for the recipient
                    $name = $notifiable->name ?? 'User';
                    
                    // Set the recipient with both email and name
                    $message->to($email, $name);
                    
                    return $message;
                }
            };
        });
    }
}
