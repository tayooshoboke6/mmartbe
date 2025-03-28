<?php

namespace Tests;

use SendinBlue\Client\Api\TransactionalEmailsApi;
use SendinBlue\Client\Configuration;
use SendinBlue\Client\Model\SendSmtpEmail;
use SendinBlue\Client\Model\SendSmtpEmailTo;

class BrevoTest extends TestCase
{
    /**
     * Test direct Brevo API integration.
     *
     * @return void
     */
    public function testDirectBrevoApi()
    {
        // Get API key from config
        $apiKey = config('services.brevo.key');
        
        // Set up Brevo API client
        $config = Configuration::getDefaultConfiguration()
            ->setApiKey('api-key', $apiKey);
        
        $apiInstance = new TransactionalEmailsApi(null, $config);
        
        // Create a test email
        $sendSmtpEmail = new SendSmtpEmail();
        
        // Set sender
        $sendSmtpEmail->setSender([
            'name' => 'M-Mart+ Support',
            'email' => config('mail.from.address', 'noreply@mmartplus.com'),
        ]);
        
        // Set recipient with explicit name
        $sendSmtpEmail->setTo([
            new SendSmtpEmailTo([
                'name' => 'Test User',
                'email' => 'tayooshoboke6@gmail.com',
            ])
        ]);
        
        // Set subject and content
        $sendSmtpEmail->setSubject('Test Email from M-Mart+ Direct API');
        $sendSmtpEmail->setHtmlContent('<p>This is a test email sent directly through the Brevo API.</p>');
        
        try {
            // Send the email
            $result = $apiInstance->sendTransacEmail($sendSmtpEmail);
            echo "Email sent successfully: " . json_encode($result) . "\n";
            return $result;
        } catch (\Exception $e) {
            echo "Exception when calling TransactionalEmailsApi->sendTransacEmail: " . $e->getMessage() . "\n";
            throw $e;
        }
    }
}
