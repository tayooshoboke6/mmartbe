<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TermiiService
{
    /**
     * @var string
     */
    protected $apiKey;

    /**
     * @var string
     */
    protected $apiUrl;

    /**
     * @var string
     */
    protected $senderId;

    /**
     * TermiiService constructor.
     *
     * @param string $apiKey
     * @param string $apiUrl
     * @param string $senderId
     */
    public function __construct($apiKey = null, $apiUrl = null, $senderId = null)
    {
        $this->apiKey = $apiKey ?? env('TERMII_API_KEY');
        $this->apiUrl = $apiUrl ?? env('TERMII_API_URL', 'https://api.ng.termii.com/api');
        $this->senderId = $senderId ?? env('TERMII_SENDER_ID', 'MMartPlus');
    }

    /**
     * Format phone number to international format
     *
     * @param string $phoneNumber
     * @return string
     */
    public function formatPhoneNumber($phoneNumber)
    {
        // Remove any non-numeric characters
        $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);
        
        // If the number doesn't start with the country code (e.g., 234 for Nigeria)
        // Add the country code (assuming Nigeria as default)
        if (strlen($phoneNumber) <= 10) {
            $phoneNumber = '234' . ltrim($phoneNumber, '0');
        }
        
        return $phoneNumber;
    }

    /**
     * Check account balance
     *
     * @return array
     */
    public function balance()
    {
        try {
            $response = Http::get($this->apiUrl . '/get-balance', [
                'api_key' => $this->apiKey
            ]);
            
            return $this->processResponse($response, 'Balance check');
        } catch (\Exception $e) {
            return $this->handleException($e, 'Balance check');
        }
    }

    /**
     * Get message history
     *
     * @return array
     */
    public function history()
    {
        try {
            $response = Http::get($this->apiUrl . '/sms/inbox', [
                'api_key' => $this->apiKey
            ]);
            
            return $this->processResponse($response, 'Message history');
        } catch (\Exception $e) {
            return $this->handleException($e, 'Message history');
        }
    }

    /**
     * Send a message
     *
     * @param string $to
     * @param string $from
     * @param string $message
     * @param string $channel
     * @param bool $media
     * @param string|null $mediaUrl
     * @param string|null $mediaCaption
     * @return array
     */
    public function sendMessage($to, $from = null, $message = null, $channel = "generic", $media = false, $mediaUrl = null, $mediaCaption = null)
    {
        try {
            // Format phone number if needed
            $to = $this->formatPhoneNumber($to);
            $from = $from ?? $this->senderId;
            
            $payload = [
                'api_key' => $this->apiKey,
                'to' => $to,
                'from' => $from,
                'sms' => $message,
                'channel' => $channel,
                'type' => 'plain'
            ];
            
            // Add media parameters if media is true
            if ($media && $mediaUrl) {
                $payload['media'] = [
                    'url' => $mediaUrl,
                    'caption' => $mediaCaption
                ];
            }
            
            $response = Http::post($this->apiUrl . '/sms/send', $payload);
            
            return $this->processResponse($response, 'Send message');
        } catch (\Exception $e) {
            return $this->handleException($e, 'Send message');
        }
    }

    /**
     * Send WhatsApp message
     *
     * @param string $to
     * @param string $message
     * @param bool $media
     * @param string|null $mediaUrl
     * @param string|null $mediaCaption
     * @return array
     */
    public function sendWhatsAppMessage($to, $message, $media = false, $mediaUrl = null, $mediaCaption = null)
    {
        return $this->sendMessage($to, $this->senderId, $message, 'whatsapp', $media, $mediaUrl, $mediaCaption);
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
     * @param string $channel
     * @return array
     */
    public function sendOtp($to, $message, $pinLength = 6, $pinAttempts = 3, $pinTimeToLive = 30, $pinType = 'NUMERIC', $channel = 'dnd')
    {
        try {
            // Format phone number if needed
            $to = $this->formatPhoneNumber($to);

            // Extract the verification code from the message for logging
            preg_match('/verification code is (\d+)/', $message, $matches);
            $code = $matches[1] ?? 'unknown';
            
            // Log the request details
            Log::info('Sending OTP via Termii', [
                'phone' => $to,
                'code' => $code,
                'channel' => $channel,
                'sender_id' => $this->senderId,
                'message' => $message
            ]);

            // For DND channel, we need to use the regular SMS endpoint with the verification message
            if ($channel === 'dnd') {
                $payload = [
                    'api_key' => $this->apiKey,
                    'to' => $to,
                    'from' => $this->senderId,
                    'sms' => $message,
                    'type' => 'plain',
                    'channel' => 'dnd'
                ];
                
                Log::debug('Termii DND payload', $payload);
                
                $response = Http::post($this->apiUrl . '/sms/send', $payload);
            } else {
                // Use the OTP endpoint for other channels
                $payload = [
                    'api_key' => $this->apiKey,
                    'message_type' => 'NUMERIC',
                    'to' => $to,
                    'from' => $this->senderId,
                    'channel' => $channel,
                    'pin_attempts' => $pinAttempts,
                    'pin_time_to_live' => $pinTimeToLive,
                    'pin_length' => $pinLength,
                    'pin_placeholder' => '< 1234 >',
                    'message_text' => $message,
                    'pin_type' => $pinType
                ];
                
                Log::debug('Termii OTP payload', $payload);
                
                $response = Http::post($this->apiUrl . '/sms/otp/send', $payload);
            }
            
            // Log the response
            Log::info('Termii API response', [
                'status' => $response->status(),
                'body' => $response->json(),
                'phone' => $to
            ]);
            
            return $this->processResponse($response, 'Send OTP');
        } catch (\Exception $e) {
            Log::error('Termii API error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'phone' => $to ?? 'unknown'
            ]);
            return $this->handleException($e, 'Send OTP');
        }
    }

    /**
     * Send WhatsApp OTP
     *
     * @param string $to
     * @param string $message
     * @param int $pinLength
     * @param int $pinAttempts
     * @param int $pinTimeToLive
     * @param string $pinType
     * @return array
     */
    public function sendWhatsAppOtp($to, $message, $pinLength = 6, $pinAttempts = 3, $pinTimeToLive = 5, $pinType = 'NUMERIC')
    {
        return $this->sendOtp($to, $message, $pinLength, $pinAttempts, $pinTimeToLive, $pinType, 'whatsapp');
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
            
            return $this->processResponse($response, 'Verify OTP');
        } catch (\Exception $e) {
            return $this->handleException($e, 'Verify OTP');
        }
    }

    /**
     * Send voice OTP
     *
     * @param string $to
     * @param int $pinAttempts
     * @param int $pinTimeToLive
     * @param int $pinLength
     * @return array
     */
    public function sendVoiceOtp($to, $pinAttempts = 3, $pinTimeToLive = 5, $pinLength = 6)
    {
        try {
            // Format phone number if needed
            $to = $this->formatPhoneNumber($to);

            $response = Http::post($this->apiUrl . '/sms/otp/send/voice', [
                'api_key' => $this->apiKey,
                'phone_number' => $to,
                'pin_attempts' => $pinAttempts,
                'pin_time_to_live' => $pinTimeToLive,
                'pin_length' => $pinLength
            ]);
            
            return $this->processResponse($response, 'Send Voice OTP');
        } catch (\Exception $e) {
            return $this->handleException($e, 'Send Voice OTP');
        }
    }

    /**
     * Send in-app OTP
     *
     * @param string $to
     * @param int $pinAttempts
     * @param int $pinTimeToLive
     * @param int $pinLength
     * @param string $pinType
     * @return array
     */
    public function sendInAppOtp($to, $pinAttempts = 3, $pinTimeToLive = 5, $pinLength = 6, $pinType = 'NUMERIC')
    {
        try {
            // Format phone number if needed
            $to = $this->formatPhoneNumber($to);

            $response = Http::post($this->apiUrl . '/sms/otp/generate', [
                'api_key' => $this->apiKey,
                'phone_number' => $to,
                'pin_attempts' => $pinAttempts,
                'pin_time_to_live' => $pinTimeToLive,
                'pin_length' => $pinLength,
                'pin_type' => $pinType
            ]);
            
            return $this->processResponse($response, 'Send In-App OTP');
        } catch (\Exception $e) {
            return $this->handleException($e, 'Send In-App OTP');
        }
    }

    /**
     * Process HTTP response
     *
     * @param \Illuminate\Http\Client\Response $response
     * @param string $operation
     * @return array
     */
    protected function processResponse($response, $operation)
    {
        $result = $response->json();
        
        if ($response->successful()) {
            Log::info("{$operation} successful", [
                'response' => $result
            ]);
            
            return [
                'success' => true,
                'message' => "{$operation} successful",
                'data' => $result
            ];
        } else {
            Log::error("Failed to {$operation}", [
                'error' => $result
            ]);
            
            return [
                'success' => false,
                'message' => "Failed to {$operation}: " . ($result['message'] ?? 'Unknown error'),
                'data' => $result
            ];
        }
    }

    /**
     * Handle exceptions
     *
     * @param \Exception $e
     * @param string $operation
     * @return array
     */
    protected function handleException(\Exception $e, $operation)
    {
        Log::error("Exception while {$operation}: " . $e->getMessage(), [
            'exception' => $e
        ]);
        
        return [
            'success' => false,
            'message' => "Exception while {$operation}: " . $e->getMessage()
        ];
    }
}
