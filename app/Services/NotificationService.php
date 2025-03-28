<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Mail\OrderConfirmationMail;
use App\Mail\OrderStatusUpdateMail;
use App\Mail\LowStockAlertMail;
use App\Mail\NewsletterSubscriptionMail;
use App\Mail\MarketingMail;
use App\Services\TermiiService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * Send order confirmation email if enabled in settings
     *
     * @param Order $order
     * @return bool
     */
    public static function sendOrderConfirmation(Order $order)
    {
        try {
            // Check if order confirmation emails are enabled
            $enabled = Setting::getValue('order_confirmation_emails', 'true');
            
            if ($enabled !== 'true') {
                Log::info('Order confirmation emails are disabled in settings');
                return false;
            }
            
            $user = $order->user;
            if (!$user || !$user->email) {
                Log::error('Cannot send order confirmation: User or email not found for order #' . $order->order_number);
                return false;
            }
            
            Mail::to($user->email)->send(new OrderConfirmationMail($order));
            Log::info('Order confirmation email sent for order #' . $order->order_number);
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send order confirmation email: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send order status update email if enabled in settings
     *
     * @param Order $order
     * @param string $oldStatus
     * @param string $newStatus
     * @return bool
     */
    public static function sendOrderStatusUpdate(Order $order, $oldStatus, $newStatus)
    {
        try {
            // Check if order status update emails are enabled
            $enabled = Setting::getValue('order_status_update_emails', 'true');
            
            if ($enabled !== 'true') {
                Log::info('Order status update emails are disabled in settings');
                return false;
            }
            
            $user = $order->user;
            if (!$user || !$user->email) {
                Log::error('Cannot send status update: User or email not found for order #' . $order->order_number);
                return false;
            }
            
            Mail::to($user->email)->send(new OrderStatusUpdateMail($order, $oldStatus, $newStatus));
            Log::info("Order status update email sent for order #{$order->order_number}: {$oldStatus} -> {$newStatus}");
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send order status update email: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send low stock alert email if enabled in settings
     *
     * @param Product $product
     * @param int $threshold
     * @return bool
     */
    public static function sendLowStockAlert(Product $product, $threshold)
    {
        try {
            // Check if low stock alerts are enabled
            $enabled = Setting::getValue('low_stock_alerts', 'true');
            
            if ($enabled !== 'true') {
                Log::info('Low stock alerts are disabled in settings');
                return false;
            }
            
            // Get admin email from settings
            $adminEmail = Setting::getValue('store_email', 'contact@mmart.com');
            
            Mail::to($adminEmail)->send(new LowStockAlertMail($product, $threshold));
            Log::info("Low stock alert email sent for product #{$product->id}: {$product->name}");
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send low stock alert email: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send newsletter subscription notification if enabled in settings
     *
     * @param User $subscriber
     * @return bool
     */
    public static function sendNewsletterSubscriptionNotification(User $subscriber)
    {
        try {
            // Check if newsletter subscription notifications are enabled
            $enabled = Setting::getValue('newsletter_subscription_notifications', 'false');
            
            if ($enabled !== 'true') {
                Log::info('Newsletter subscription notifications are disabled in settings');
                return false;
            }
            
            // Get admin email from settings
            $adminEmail = Setting::getValue('store_email', 'contact@mmart.com');
            
            Mail::to($adminEmail)->send(new NewsletterSubscriptionMail($subscriber));
            Log::info("Newsletter subscription notification sent for user #{$subscriber->id}: {$subscriber->email}");
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send newsletter subscription notification: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send marketing email if enabled in settings
     *
     * @param User $user
     * @param string $subject
     * @param string $content
     * @return bool
     */
    public static function sendMarketingEmail(User $user, $subject, $content)
    {
        try {
            // Check if marketing emails are enabled
            $enabled = Setting::getValue('marketing_emails', 'false');
            
            if ($enabled !== 'true') {
                Log::info('Marketing emails are disabled in settings');
                return false;
            }
            
            // Check if user has opted in to marketing emails
            if (!$user->marketing_consent) {
                Log::info("User #{$user->id} has not opted in to marketing emails");
                return false;
            }
            
            Mail::to($user->email)->send(new MarketingMail($user, $subject, $content));
            Log::info("Marketing email sent to user #{$user->id}: {$user->email}");
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send marketing email: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send order confirmation SMS if enabled in settings
     *
     * @param Order $order
     * @return bool
     */
    public static function sendOrderConfirmationSms(Order $order)
    {
        try {
            // Check if SMS notifications are enabled
            $enabled = Setting::getValue('sms_notifications', 'false');
            $orderSmsEnabled = Setting::getValue('order_confirmation_sms', 'false');
            
            if ($enabled !== 'true' || $orderSmsEnabled !== 'true') {
                Log::info('Order confirmation SMS are disabled in settings');
                return false;
            }
            
            $user = $order->user;
            if (!$user || !$user->phone) {
                Log::error('Cannot send order confirmation SMS: User or phone not found for order #' . $order->order_number);
                return false;
            }
            
            $message = "Your order #{$order->order_number} has been confirmed. Total: ₦" . 
                       number_format($order->grand_total, 2) . ". Thank you for shopping with M-Mart+!";
            
            $termiiService = new TermiiService();
            $result = $termiiService->sendSms($user->phone, $message);
            
            if ($result['success']) {
                Log::info('Order confirmation SMS sent for order #' . $order->order_number);
                return true;
            } else {
                Log::error('Failed to send order confirmation SMS: ' . $result['message']);
                return false;
            }
        } catch (\Exception $e) {
            Log::error('Failed to send order confirmation SMS: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send order status update SMS if enabled in settings
     *
     * @param Order $order
     * @param string $oldStatus
     * @param string $newStatus
     * @return bool
     */
    public static function sendOrderStatusUpdateSms(Order $order, $oldStatus, $newStatus)
    {
        try {
            // Check if SMS notifications are enabled
            $enabled = Setting::getValue('sms_notifications', 'false');
            $statusSmsEnabled = Setting::getValue('order_status_update_sms', 'false');
            
            if ($enabled !== 'true' || $statusSmsEnabled !== 'true') {
                Log::info('Order status update SMS are disabled in settings');
                return false;
            }
            
            $user = $order->user;
            if (!$user || !$user->phone) {
                Log::error('Cannot send status update SMS: User or phone not found for order #' . $order->order_number);
                return false;
            }
            
            $statusMessage = '';
            if ($newStatus == 'processing') {
                $statusMessage = "We are processing your order.";
            } elseif ($newStatus == 'shipped') {
                $statusMessage = "Your order has been shipped and is on its way.";
            } elseif ($newStatus == 'delivered') {
                $statusMessage = "Your order has been delivered. Thank you for shopping with us!";
            } elseif ($newStatus == 'cancelled') {
                $statusMessage = "Your order has been cancelled. Please contact support for assistance.";
            } else {
                $statusMessage = "Your order status has been updated to " . ucfirst($newStatus) . ".";
            }
            
            $message = "Order #{$order->order_number} Update: {$statusMessage}";
            
            $termiiService = new TermiiService();
            $result = $termiiService->sendSms($user->phone, $message);
            
            if ($result['success']) {
                Log::info("Order status update SMS sent for order #{$order->order_number}: {$oldStatus} -> {$newStatus}");
                return true;
            } else {
                Log::error('Failed to send order status update SMS: ' . $result['message']);
                return false;
            }
        } catch (\Exception $e) {
            Log::error('Failed to send order status update SMS: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send low stock alert SMS if enabled in settings
     *
     * @param Product $product
     * @param int $threshold
     * @return bool
     */
    public static function sendLowStockAlertSms(Product $product, $threshold)
    {
        try {
            // Check if SMS notifications are enabled
            $enabled = Setting::getValue('sms_notifications', 'false');
            $lowStockSmsEnabled = Setting::getValue('low_stock_sms_alerts', 'false');
            
            if ($enabled !== 'true' || $lowStockSmsEnabled !== 'true') {
                Log::info('Low stock SMS alerts are disabled in settings');
                return false;
            }
            
            // Get admin phone from settings
            $adminPhone = Setting::getValue('admin_phone', '');
            
            if (empty($adminPhone)) {
                Log::error('Cannot send low stock SMS alert: Admin phone not configured');
                return false;
            }
            
            $message = "LOW STOCK ALERT: {$product->name} (SKU: {$product->sku}) has only {$product->stock_quantity} units left. Threshold: {$threshold}.";
            
            $termiiService = new TermiiService();
            $result = $termiiService->sendSms($adminPhone, $message);
            
            if ($result['success']) {
                Log::info("Low stock SMS alert sent for product #{$product->id}: {$product->name}");
                return true;
            } else {
                Log::error('Failed to send low stock SMS alert: ' . $result['message']);
                return false;
            }
        } catch (\Exception $e) {
            Log::error('Failed to send low stock SMS alert: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send promotional SMS with coupon code to customers
     *
     * @param array $recipients Array of phone numbers or User objects
     * @param string $couponCode
     * @param string $discount
     * @param string|null $expiryDate
     * @return array
     */
    public static function sendPromotionalCouponSms($recipients, $couponCode, $discount, $expiryDate = null)
    {
        try {
            // Check if promotional SMS are enabled
            $smsEnabled = Setting::getValue('sms_notifications', 'false');
            $promotionalSmsEnabled = Setting::getValue('promotional_sms', 'false');
            
            if ($smsEnabled !== 'true' || $promotionalSmsEnabled !== 'true') {
                Log::info('Promotional SMS are disabled in settings');
                return [
                    'success' => false,
                    'message' => 'Promotional SMS are disabled in settings'
                ];
            }
            
            // Process recipients
            $phoneNumbers = [];
            foreach ($recipients as $recipient) {
                if (is_object($recipient) && $recipient instanceof User) {
                    if (!empty($recipient->phone)) {
                        $phoneNumbers[] = $recipient->phone;
                    }
                } else if (is_string($recipient)) {
                    $phoneNumbers[] = $recipient;
                }
            }
            
            if (empty($phoneNumbers)) {
                Log::info('No valid phone numbers found for promotional SMS');
                return [
                    'success' => false,
                    'message' => 'No valid phone numbers found'
                ];
            }
            
            // Send coupon SMS via TermiiService
            $termiiService = new TermiiService();
            $result = $termiiService->sendCouponSms($phoneNumbers, $couponCode, $discount, $expiryDate);
            
            // Log the result
            if ($result['success']) {
                Log::info('Promotional coupon SMS sent successfully', [
                    'recipients_count' => count($phoneNumbers),
                    'coupon_code' => $couponCode
                ]);
            } else {
                Log::error('Failed to send promotional coupon SMS', [
                    'recipients_count' => count($phoneNumbers),
                    'coupon_code' => $couponCode,
                    'error' => $result['message']
                ]);
            }
            
            return $result;
        } catch (\Exception $e) {
            Log::error('Exception while sending promotional coupon SMS: ' . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Exception while sending promotional coupon SMS: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Send promotional SMS with custom message to customers
     *
     * @param array $recipients Array of phone numbers or User objects
     * @param string $message
     * @return array
     */
    public static function sendCustomPromotionalSms($recipients, $message)
    {
        try {
            // Check if promotional SMS are enabled
            $smsEnabled = Setting::getValue('sms_notifications', 'false');
            $promotionalSmsEnabled = Setting::getValue('promotional_sms', 'false');
            
            if ($smsEnabled !== 'true' || $promotionalSmsEnabled !== 'true') {
                Log::info('Promotional SMS are disabled in settings');
                return [
                    'success' => false,
                    'message' => 'Promotional SMS are disabled in settings'
                ];
            }
            
            // Process recipients
            $phoneNumbers = [];
            foreach ($recipients as $recipient) {
                if (is_object($recipient) && $recipient instanceof User) {
                    if (!empty($recipient->phone)) {
                        $phoneNumbers[] = $recipient->phone;
                    }
                } else if (is_string($recipient)) {
                    $phoneNumbers[] = $recipient;
                }
            }
            
            if (empty($phoneNumbers)) {
                Log::info('No valid phone numbers found for promotional SMS');
                return [
                    'success' => false,
                    'message' => 'No valid phone numbers found'
                ];
            }
            
            // Send custom SMS via TermiiService
            $termiiService = new TermiiService();
            $result = $termiiService->sendBulkSms($phoneNumbers, $message);
            
            // Log the result
            if ($result['success']) {
                Log::info('Custom promotional SMS sent successfully', [
                    'recipients_count' => count($phoneNumbers)
                ]);
            } else {
                Log::error('Failed to send custom promotional SMS', [
                    'recipients_count' => count($phoneNumbers),
                    'error' => $result['message']
                ]);
            }
            
            return $result;
        } catch (\Exception $e) {
            Log::error('Exception while sending custom promotional SMS: ' . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Exception while sending custom promotional SMS: ' . $e->getMessage()
            ];
        }
    }
}
