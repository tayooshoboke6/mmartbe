<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BrevoChannel
{
    /**
     * Send the given notification via Brevo API.
     *
     * @param  mixed  $notifiable
     * @param  \Illuminate\Notifications\Notification  $notification
     * @return void
     */
    public function send($notifiable, Notification $notification)
    {
        try {
            // Get the mail representation of the notification
            $mailMessage = $notification->toMail($notifiable);
            
            // Log what we received from the notification
            Log::info('BrevoChannel processing notification', [
                'notification_class' => get_class($notification),
                'has_subject' => isset($mailMessage->subject),
                'has_action' => isset($mailMessage->actionText) && isset($mailMessage->actionUrl),
                'action_url' => $mailMessage->actionUrl ?? 'none',
            ]);
            
            // Extract content for Brevo API
            $subject = $mailMessage->subject ?? 'No Subject';
            
            // Build HTML content from the notification
            $htmlContent = $this->buildHtmlContent($mailMessage);
            
            // Log the HTML content for debugging
            Log::info('BrevoChannel HTML content', [
                'html_content_length' => strlen($htmlContent),
            ]);

            // Ensure recipient name & email
            $recipientName = $notifiable->name ?? 'User';
            $recipientEmail = $notifiable->routeNotificationFor('mail', $notification);
            
            Log::info('BrevoChannel preparing to send email', [
                'to_email' => $recipientEmail,
                'to_name' => $recipientName,
                'subject' => $subject,
            ]);

            // Make API request to Brevo
            $payload = [
                "sender" => [
                    "name" => env('MAIL_FROM_NAME', 'M-Mart+'),
                    "email" => env('MAIL_FROM_ADDRESS'),
                ],
                "to" => [
                    [
                        "email" => $recipientEmail,
                        "name" => $recipientName,
                    ]
                ],
                "subject" => $subject,
                "htmlContent" => $htmlContent,
            ];
            
            // Log the full payload
            Log::info('BrevoChannel API payload', [
                'payload_size' => strlen(json_encode($payload)),
            ]);

            $response = Http::withHeaders([
                'api-key' => env('BREVO_API_KEY'),
                'Content-Type' => 'application/json',
            ])->post('https://api.brevo.com/v3/smtp/email', $payload);

            // Log response for debugging
            if (!$response->successful()) {
                Log::error('Brevo Email Error:', $response->json());
                Log::error('Brevo Email Request Failed', [
                    'status_code' => $response->status(),
                    'response_body' => $response->body(),
                ]);
            } else {
                Log::info('Brevo Email Sent Successfully', [
                    'to' => $recipientEmail,
                    'subject' => $subject,
                    'response' => $response->json(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Exception in BrevoChannel', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Build HTML content from mail message
     *
     * @param mixed $mailMessage
     * @return string
     */
    private function buildHtmlContent($mailMessage)
    {
        // Use the custom template for password reset emails
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>' . ($mailMessage->subject ?? 'M-Mart+ Notification') . '</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 600px;
            margin: 50px auto;
            background: #ffffff;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            text-align: center;
        }
        .logo {
            background-color: #0056b3;
            color: #FFD700;
            font-size: 24px;
            font-weight: bold;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
        }
        .logo img {
            vertical-align: middle;
            margin-right: 10px;
            width: 24px;
            height: 24px;
        }
        h2 {
            color: #333;
            margin-bottom: 10px;
        }
        p {
            color: #555;
            font-size: 16px;
            line-height: 1.5;
        }
        .btn {
            display: inline-block;
            background: #ffcc00;
            color: #000;
            padding: 12px 20px;
            text-decoration: none;
            font-size: 16px;
            border-radius: 5px;
            font-weight: bold;
            margin-top: 20px;
        }
        .footer {
            margin-top: 30px;
            font-size: 14px;
            color: #777;
        }
        @media screen and (max-width: 600px) {
            .container {
                width: 90%;
            }
            .btn {
                width: 100%;
                padding: 15px 0;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">
            <!-- Shopping cart icon SVG that matches the first screenshot -->
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill: #FFD700; vertical-align: middle; margin-right: 8px;">
                <path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z"/>
            </svg>
            M-Mart+
        </div>
        <h2>' . ($mailMessage->subject ?? 'Password Reset Request') . '</h2>';
        
        // Add intro lines
        if (isset($mailMessage->introLines) && is_array($mailMessage->introLines)) {
            foreach ($mailMessage->introLines as $line) {
                $html .= '<p>' . $line . '</p>';
            }
        }
        
        // Add action button if available
        if (isset($mailMessage->actionText) && isset($mailMessage->actionUrl)) {
            $html .= '<p>Click the button below to reset your password:</p>';
            $html .= '<a href="' . $mailMessage->actionUrl . '" class="btn">' . $mailMessage->actionText . '</a>';
            
            // Add the URL as text as well (for email clients that block buttons)
            $html .= '<p>If the button above doesn\'t work, copy and paste the following link into your browser:</p>';
            $html .= '<p style="word-break: break-all; color: #007BFF;">' . $mailMessage->actionUrl . '</p>';
        }
        
        // Add outro lines
        if (isset($mailMessage->outroLines) && is_array($mailMessage->outroLines)) {
            foreach ($mailMessage->outroLines as $line) {
                $html .= '<p>' . $line . '</p>';
            }
        }
        
        // Add footer
        $html .= '<div class="footer">
            <p>Need help? Contact our support team at <a href="mailto:support@mmartplus.com">support@mmartplus.com</a></p>
            <p>&copy; ' . date('Y') . ' M-Mart+. All Rights Reserved.</p>
        </div>
    </div>
</body>
</html>';
        
        return $html;
    }
}
