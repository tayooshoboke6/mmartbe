<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class OrderSmsService
{
    /**
     * Send an SMS using direct cURL implementation.
     *
     * @param  string  $phone
     * @param  string  $message
     * @return array
     */
    private static function sendSms($phone, $message)
    {
        $apiKey = env('TERMII_API_KEY');
        $senderId = env('TERMII_SENDER_ID', 'N-Alert');

        // Format the phone number
        $phone = self::formatPhoneNumber($phone);

        $url = 'https://api.ng.termii.com/api/sms/send';
        $data = [
            'to' => $phone,
            'from' => $senderId,
            'sms' => $message,
            'type' => 'plain',
            'channel' => 'dnd',
            'api_key' => $apiKey,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        Log::info('Order SMS API Response', [
            'phone' => $phone,
            'message' => $message,
            'response' => $response,
            'http_code' => $httpCode,
            'error' => $error
        ]);

        if ($error) {
            return [
                'success' => false,
                'error' => $error
            ];
        }

        return [
            'success' => true,
            'response' => json_decode($response, true)
        ];
    }

    /**
     * Format the phone number to ensure it has the country code.
     *
     * @param  string  $phone
     * @return string
     */
    private static function formatPhoneNumber($phone)
    {
        // Remove any non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // If the number doesn't start with the country code, add it
        if (!str_starts_with($phone, '234')) {
            // If it starts with 0, replace it with 234
            if (str_starts_with($phone, '0')) {
                $phone = '234' . substr($phone, 1);
            } else {
                // Otherwise, just prepend 234
                $phone = '234' . $phone;
            }
        }

        return $phone;
    }

    /**
     * Send order confirmation SMS
     *
     * @param Order $order
     * @return bool
     */
    public static function sendOrderConfirmation(Order $order)
    {
        try {
            // Check if order confirmation SMS are enabled
            $enabled = Setting::getValue('order_confirmation_sms', 'true');
            
            if ($enabled !== 'true') {
                Log::info('Order confirmation SMS are disabled in settings');
                return false;
            }
            
            // Get the phone number from the order (shipping_phone) or fall back to user's phone
            $phone = $order->shipping_phone;
            $name = $order->shipping_name;
            
            // If no shipping phone is available, try to get customer_phone
            if (empty($phone) && !empty($order->customer_phone)) {
                $phone = $order->customer_phone;
            }
            
            // If still no phone, fall back to the user's phone
            if (empty($phone)) {
                $user = $order->user;
                if ($user && $user->phone) {
                    $phone = $user->phone;
                    if (empty($name)) {
                        $name = $user->name;
                    }
                }
            }
            
            // If we still don't have a phone number, log an error and return
            if (empty($phone)) {
                Log::error('Cannot send order confirmation SMS: No phone number found for order #' . $order->order_number);
                return false;
            }
            
            // If no name is available, use a generic greeting
            if (empty($name)) {
                $name = 'Customer';
            }
            
            // Format the message according to the approved template
            $message = "Dear {$name}, your order #{$order->order_number} has been confirmed and is being prepared.\nPowered by MMart Plus.";
            
            // Send the SMS
            $result = self::sendSms($phone, $message);
            
            if ($result['success']) {
                Log::info('Order confirmation SMS sent to ' . $phone . ' for order #' . $order->order_number);
                return true;
            } else {
                Log::error('Failed to send order confirmation SMS: ' . ($result['error'] ?? 'Unknown error'));
                return false;
            }
        } catch (\Exception $e) {
            Log::error('Exception while sending order confirmation SMS: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send order status update SMS
     *
     * @param Order $order
     * @param string $oldStatus
     * @param string $newStatus
     * @return bool
     */
    public static function sendOrderStatusUpdate(Order $order, $oldStatus, $newStatus)
    {
        try {
            // Check if order status update SMS are enabled
            $enabled = Setting::getValue('order_status_update_sms', 'true');
            
            if ($enabled !== 'true') {
                Log::info('Order status update SMS are disabled in settings');
                return false;
            }
            
            // Get the phone number from the order (shipping_phone) or fall back to user's phone
            $phone = $order->shipping_phone;
            $name = $order->shipping_name;
            
            // If no shipping phone is available, try to get customer_phone
            if (empty($phone) && !empty($order->customer_phone)) {
                $phone = $order->customer_phone;
            }
            
            // If still no phone, fall back to the user's phone
            if (empty($phone)) {
                $user = $order->user;
                if ($user && $user->phone) {
                    $phone = $user->phone;
                    if (empty($name)) {
                        $name = $user->name;
                    }
                }
            }
            
            // If we still don't have a phone number, log an error and return
            if (empty($phone)) {
                Log::error('Cannot send order status update SMS: No phone number found for order #' . $order->order_number);
                return false;
            }
            
            // If no name is available, use a generic greeting
            if (empty($name)) {
                $name = 'Customer';
            }
            
            // Format the message according to the approved template based on the new status
            $message = '';
            if ($newStatus === 'out_for_delivery') {
                $message = "Dear {$name}, your order #{$order->order_number} is out for delivery. Please keep your phone available for contact.\nPowered by MMart Plus.";
            } elseif ($newStatus === 'delivered') {
                $message = "Dear {$name}, your order #{$order->order_number} has been successfully delivered. Thank you for shopping with us!\nPowered by MMart Plus.";
            } else {
                // For other status updates, use a generic message
                $message = "Dear {$name}, your order #{$order->order_number} status has been updated to " . ucfirst(str_replace('_', ' ', $newStatus)) . ".\nPowered by MMart Plus.";
            }
            
            // Send the SMS
            $result = self::sendSms($phone, $message);
            
            if ($result['success']) {
                Log::info("Order status update SMS sent to {$phone} for order #{$order->order_number}: {$oldStatus} -> {$newStatus}");
                return true;
            } else {
                Log::error('Failed to send order status update SMS: ' . ($result['error'] ?? 'Unknown error'));
                return false;
            }
        } catch (\Exception $e) {
            Log::error('Exception while sending order status update SMS: ' . $e->getMessage());
            return false;
        }
    }
}
