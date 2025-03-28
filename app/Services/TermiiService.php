<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TermiiService
{
    /**
     * Termii API key
     *
     * @var string
     */
    protected $apiKey;

    /**
     * Termii API URL
     *
     * @var string
     */
    protected $apiUrl;

    /**
     * Sender ID
     *
     * @var string
     */
    protected $senderId;

    /**
     * Create a new Termii service instance.
     *
     * @return void
     */
    public function __construct()
    {
        // First try to get from environment variables
        $this->apiKey = env('TERMII_API_KEY', '');
        $this->apiUrl = env('TERMII_API_URL', 'https://api.ng.termii.com/api');
        $this->senderId = env('TERMII_SENDER_ID', 'MMartPlus');
        
        // If API key is not in env, try to get from settings
        if (empty($this->apiKey)) {
            $this->apiKey = Setting::getValue('termii_api_key', '');
        }
        
        // If sender ID is not in env, try to get from settings
        if (empty($this->senderId)) {
            $this->senderId = Setting::getValue('termii_sender_id', 'MMartPlus');
        }
    }

    /**
     * Send an SMS message
     *
     * @param string $to
     * @param string $message
     * @return array
     */
    public function sendSms($to, $message)
    {
        try {
            // Check if SMS notifications are enabled
            $enabled = Setting::getValue('sms_notifications', 'false');
            if ($enabled !== 'true') {
                Log::info('SMS notifications are disabled in settings');
                return [
                    'success' => false,
                    'message' => 'SMS notifications are disabled in settings'
                ];
            }

            // Format phone number if needed (remove leading zero and add country code if not present)
            $to = $this->formatPhoneNumber($to);

            $response = Http::post($this->apiUrl . '/sms/send', [
                'api_key' => $this->apiKey,
                'to' => $to,
                'from' => $this->senderId,
                'sms' => $message,
                'type' => 'plain',
                'channel' => 'generic'
            ]);

            $result = $response->json();

            if ($response->successful()) {
                Log::info('SMS sent successfully', [
                    'to' => $to,
                    'message_length' => strlen($message),
                    'response' => $result
                ]);
                
                return [
                    'success' => true,
                    'message' => 'SMS sent successfully',
                    'data' => $result
                ];
            } else {
                Log::error('Failed to send SMS', [
                    'to' => $to,
                    'error' => $result
                ]);
                
                return [
                    'success' => false,
                    'message' => 'Failed to send SMS: ' . ($result['message'] ?? 'Unknown error'),
                    'data' => $result
                ];
            }
        } catch (\Exception $e) {
            Log::error('Exception while sending SMS: ' . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Exception while sending SMS: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Send an OTP message
     *
     * @param string $to
     * @param string $message
     * @param int $pinLength
     * @param int $pinAttempts
     * @param int $pinTimeToLive
     * @param string $pinType
     * @return array
     */
    public function sendOtp($to, $message, $pinLength = 4, $pinAttempts = 3, $pinTimeToLive = 5, $pinType = 'NUMERIC')
    {
        try {
            // Format phone number if needed
            $to = $this->formatPhoneNumber($to);

            $response = Http::post($this->apiUrl . '/sms/otp/send', [
                'api_key' => $this->apiKey,
                'message_type' => 'NUMERIC',
                'to' => $to,
                'from' => $this->senderId,
                'channel' => 'generic',
                'pin_attempts' => $pinAttempts,
                'pin_time_to_live' => $pinTimeToLive,
                'pin_length' => $pinLength,
                'pin_placeholder' => '< 1234 >',
                'message_text' => $message,
                'pin_type' => $pinType
            ]);

            $result = $response->json();

            if ($response->successful()) {
                Log::info('OTP sent successfully', [
                    'to' => $to,
                    'response' => $result
                ]);
                
                return [
                    'success' => true,
                    'message' => 'OTP sent successfully',
                    'data' => $result
                ];
            } else {
                Log::error('Failed to send OTP', [
                    'to' => $to,
                    'error' => $result
                ]);
                
                return [
                    'success' => false,
                    'message' => 'Failed to send OTP: ' . ($result['message'] ?? 'Unknown error'),
                    'data' => $result
                ];
            }
        } catch (\Exception $e) {
            Log::error('Exception while sending OTP: ' . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Exception while sending OTP: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Verify an OTP
     *
     * @param string $pinId
     * @param string $pin
     * @return array
     */
    public function verifyOtp($pinId, $pin)
    {
        try {
            $response = Http::post($this->apiUrl . '/sms/otp/verify', [
                'api_key' => $this->apiKey,
                'pin_id' => $pinId,
                'pin' => $pin
            ]);

            $result = $response->json();

            if ($response->successful() && isset($result['verified']) && $result['verified']) {
                Log::info('OTP verified successfully', [
                    'pin_id' => $pinId,
                    'response' => $result
                ]);
                
                return [
                    'success' => true,
                    'message' => 'OTP verified successfully',
                    'data' => $result
                ];
            } else {
                Log::error('Failed to verify OTP', [
                    'pin_id' => $pinId,
                    'error' => $result
                ]);
                
                return [
                    'success' => false,
                    'message' => 'Failed to verify OTP: ' . ($result['message'] ?? 'Invalid OTP'),
                    'data' => $result
                ];
            }
        } catch (\Exception $e) {
            Log::error('Exception while verifying OTP: ' . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Exception while verifying OTP: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Format phone number to international format
     *
     * @param string $phoneNumber
     * @return string
     */
    protected function formatPhoneNumber($phoneNumber)
    {
        // Remove any non-numeric characters
        $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);
        
        // If the number starts with 0, replace it with the country code (default to Nigeria +234)
        if (substr($phoneNumber, 0, 1) === '0') {
            $phoneNumber = '234' . substr($phoneNumber, 1);
        }
        
        // If the number doesn't have a country code, add the default country code
        if (strlen($phoneNumber) <= 10) {
            $phoneNumber = '234' . $phoneNumber;
        }
        
        return $phoneNumber;
    }

    /**
     * Send a bulk SMS message to multiple recipients
     *
     * @param array $recipients Array of phone numbers
     * @param string $message
     * @return array
     */
    public function sendBulkSms($recipients, $message)
    {
        try {
            // Check if SMS notifications are enabled
            $enabled = Setting::getValue('sms_notifications', 'false');
            $promotionalSmsEnabled = Setting::getValue('promotional_sms', 'false');
            
            if ($enabled !== 'true' || $promotionalSmsEnabled !== 'true') {
                Log::info('Promotional SMS are disabled in settings');
                return [
                    'success' => false,
                    'message' => 'Promotional SMS are disabled in settings'
                ];
            }

            // Format phone numbers if needed
            $formattedRecipients = [];
            foreach ($recipients as $recipient) {
                $formattedRecipients[] = $this->formatPhoneNumber($recipient);
            }
            
            // Chunk recipients into groups of 100 to avoid API limitations
            $recipientChunks = array_chunk($formattedRecipients, 100);
            $results = [];
            
            foreach ($recipientChunks as $chunk) {
                $response = Http::post($this->apiUrl . '/sms/send/bulk', [
                    'api_key' => $this->apiKey,
                    'to' => $chunk,
                    'from' => $this->senderId,
                    'sms' => $message,
                    'type' => 'plain',
                    'channel' => 'generic'
                ]);
                
                $result = $response->json();
                $results[] = $result;
                
                if (!$response->successful()) {
                    Log::error('Failed to send bulk SMS', [
                        'recipients_count' => count($chunk),
                        'error' => $result
                    ]);
                }
            }
            
            Log::info('Bulk SMS sent successfully', [
                'total_recipients' => count($recipients),
                'message_length' => strlen($message),
                'chunks_sent' => count($recipientChunks)
            ]);
            
            return [
                'success' => true,
                'message' => 'Bulk SMS sent successfully',
                'data' => $results
            ];
        } catch (\Exception $e) {
            Log::error('Exception while sending bulk SMS: ' . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Exception while sending bulk SMS: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Send a promotional SMS with coupon code
     * 
     * @param array $recipients Array of phone numbers
     * @param string $couponCode
     * @param string $discount
     * @param string $expiryDate
     * @return array
     */
    public function sendCouponSms($recipients, $couponCode, $discount, $expiryDate = null)
    {
        try {
            // Check if SMS notifications are enabled
            $enabled = Setting::getValue('sms_notifications', 'false');
            $promotionalSmsEnabled = Setting::getValue('promotional_sms', 'false');
            
            if ($enabled !== 'true' || $promotionalSmsEnabled !== 'true') {
                Log::info('Promotional SMS are disabled in settings');
                return [
                    'success' => false,
                    'message' => 'Promotional SMS are disabled in settings'
                ];
            }
            
            $storeName = Setting::getValue('store_name', 'M-Mart+');
            
            // Create message with or without expiry date
            if ($expiryDate) {
                $message = "🎁 {$storeName}: Use code {$couponCode} to get {$discount} off your next purchase! Valid until {$expiryDate}. Shop now at " . config('app.url');
            } else {
                $message = "🎁 {$storeName}: Use code {$couponCode} to get {$discount} off your next purchase! Shop now at " . config('app.url');
            }
            
            // Send the bulk SMS
            return $this->sendBulkSms($recipients, $message);
        } catch (\Exception $e) {
            Log::error('Exception while sending coupon SMS: ' . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Exception while sending coupon SMS: ' . $e->getMessage()
            ];
        }
    }
}
