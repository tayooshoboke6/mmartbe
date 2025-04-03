<?php

namespace App\Providers;

use Illuminate\Mail\MailServiceProvider as BaseMailServiceProvider;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;

class MailServiceProvider extends BaseMailServiceProvider
{
    /**
     * Register the Illuminate mailer instance.
     *
     * @return void
     */
    protected function registerIlluminateMailer()
    {
        parent::registerIlluminateMailer();

        // Register custom mail transport for Brevo
        Mail::extend('brevo', function () {
            $config = $this->app['config']->get('mail.mailers.brevo', []);
            $apiKey = $config['api_key'] ?? env('BREVO_API_KEY');
            
            return (new BrevoTransportFactory())->create(
                new Dsn(
                    'brevo+api',
                    'default',
                    $apiKey
                )
            );
        });
    }
}
