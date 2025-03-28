<?php

namespace App\Mail\Transport;

use SendinBlue\Client\Api\TransactionalEmailsApi;
use SendinBlue\Client\Configuration;
use SendinBlue\Client\Model\SendSmtpEmail;
use SendinBlue\Client\Model\SendSmtpEmailTo;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use Illuminate\Support\Facades\Log;

class BrevoTransport extends AbstractTransport
{
    /**
     * The Brevo API client instance.
     *
     * @var \SendinBlue\Client\Api\TransactionalEmailsApi
     */
    protected $client;

    /**
     * Create a new Brevo transport instance.
     *
     * @param string $apiKey
     * @return void
     */
    public function __construct(string $apiKey)
    {
        parent::__construct();

        Log::info('BrevoTransport initialized with API key', [
            'key_length' => strlen($apiKey),
            'key_exists' => !empty($apiKey)
        ]);

        $config = Configuration::getDefaultConfiguration()
            ->setApiKey('api-key', $apiKey);

        $this->client = new TransactionalEmailsApi(null, $config);
    }

    /**
     * {@inheritDoc}
     */
    protected function doSend(SentMessage $message): void
    {
        Log::info('BrevoTransport doSend called', [
            'message_class' => get_class($message->getOriginalMessage()),
            'message_id' => $message->getMessageId()
        ]);

        $email = MessageConverter::toEmail($message->getOriginalMessage());     

        $this->sendToBrevo($email);
    }

    /**
     * Send the email message to Brevo API.
     *
     * @param \Symfony\Component\Mime\Email $email
     * @return void
     */
    protected function sendToBrevo(Email $email): void
    {
        Log::info('BrevoTransport sendToBrevo called', [
            'subject' => $email->getSubject(),
            'to_count' => count($email->getTo()),
            'has_html' => !empty($email->getHtmlBody()),
            'has_text' => !empty($email->getTextBody()),
        ]);

        $sendSmtpEmail = new SendSmtpEmail();

        // Set sender
        $from = $email->getFrom();
        if (count($from) > 0) {
            $fromAddress = $from[0];
            $sendSmtpEmail->setSender([
                'name' => $fromAddress->getName() ?: $fromAddress->getAddress(),
                'email' => $fromAddress->getAddress(),
            ]);
            
            Log::info('BrevoTransport sender set', [
                'name' => $fromAddress->getName() ?: $fromAddress->getAddress(),
                'email' => $fromAddress->getAddress(),
            ]);
        } else {
            Log::warning('BrevoTransport missing sender information');
        }

        // Set recipients
        $recipients = [];
        foreach ($email->getTo() as $to) {
            $recipients[] = new SendSmtpEmailTo([
                'name' => $to->getName() ?: $to->getAddress(), // Use email as name if name is empty
                'email' => $to->getAddress(),
            ]);
            
            Log::info('BrevoTransport recipient added', [
                'name' => $to->getName() ?: $to->getAddress(),
                'email' => $to->getAddress(),
            ]);
        }
        $sendSmtpEmail->setTo($recipients);

        // Set CC recipients
        $ccRecipients = [];
        foreach ($email->getCc() as $cc) {
            $ccRecipients[] = new SendSmtpEmailTo([
                'name' => $cc->getName() ?: $cc->getAddress(), // Use email as name if name is empty
                'email' => $cc->getAddress(),
            ]);
        }
        if (!empty($ccRecipients)) {
            $sendSmtpEmail->setCc($ccRecipients);
        }

        // Set BCC recipients
        $bccRecipients = [];
        foreach ($email->getBcc() as $bcc) {
            $bccRecipients[] = new SendSmtpEmailTo([
                'name' => $bcc->getName() ?: $bcc->getAddress(), // Use email as name if name is empty
                'email' => $bcc->getAddress(),
            ]);
        }
        if (!empty($bccRecipients)) {
            $sendSmtpEmail->setBcc($bccRecipients);
        }

        // Set subject
        $sendSmtpEmail->setSubject($email->getSubject());
        Log::info('BrevoTransport subject set', ['subject' => $email->getSubject()]);

        // Set content
        $htmlContent = $email->getHtmlBody();
        $textContent = $email->getTextBody();

        if ($htmlContent) {
            $sendSmtpEmail->setHtmlContent($htmlContent);
            Log::info('BrevoTransport HTML content set', [
                'content_length' => strlen($htmlContent),
                'content_preview' => substr($htmlContent, 0, 100) . '...'
            ]);
        } else {
            Log::warning('BrevoTransport missing HTML content');
        }

        if ($textContent) {
            $sendSmtpEmail->setTextContent($textContent);
            Log::info('BrevoTransport text content set', [
                'content_length' => strlen($textContent)
            ]);
        }

        // Send the email
        try {
            Log::info('BrevoTransport attempting to send email', [
                'to' => json_encode($sendSmtpEmail->getTo()),
                'subject' => $sendSmtpEmail->getSubject(),
                'sender' => json_encode($sendSmtpEmail->getSender()),
                'api_key_length' => strlen(config('services.brevo.key')),
            ]);
            
            $result = $this->client->sendTransacEmail($sendSmtpEmail);
            
            Log::info('BrevoTransport email sent successfully', [
                'result' => json_encode($result),
                'message_id' => $result->getMessageId() ?? 'No message ID',
                'raw_response' => print_r($result, true)
            ]);
        } catch (\Exception $e) {
            Log::error('BrevoTransport failed to send email', [
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_class' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Get the string representation of the transport.
     *
     * @return string
     */
    public function __toString(): string
    {
        return 'brevo';
    }
}
