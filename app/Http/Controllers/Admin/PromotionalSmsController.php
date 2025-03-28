<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Coupon;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class PromotionalSmsController extends Controller
{
    /**
     * Send promotional SMS with coupon code to customers
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function sendCouponSms(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'coupon_id' => 'required|exists:coupons,id',
            'recipient_type' => 'required|in:all,specific',
            'recipients' => 'required_if:recipient_type,specific|array',
            'recipients.*' => 'required_if:recipient_type,specific|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Get coupon details
            $coupon = Coupon::findOrFail($request->coupon_id);
            
            // Format discount value
            $discount = '';
            if ($coupon->discount_type === 'percentage') {
                $discount = $coupon->discount_value . '%';
            } else {
                $discount = config('app.currency_symbol') . $coupon->discount_value;
            }
            
            // Format expiry date if exists
            $expiryDate = null;
            if ($coupon->expiry_date) {
                $expiryDate = date('d M Y', strtotime($coupon->expiry_date));
            }
            
            // Get recipients based on type
            $recipients = [];
            if ($request->recipient_type === 'all') {
                // Get all users with phone numbers
                $recipients = User::whereNotNull('phone')
                    ->where('phone', '!=', '')
                    ->where('role', 'customer')
                    ->pluck('phone')
                    ->toArray();
            } else {
                // Use specific recipients
                $recipients = $request->recipients;
            }
            
            // Send promotional SMS
            $result = NotificationService::sendPromotionalCouponSms(
                $recipients,
                $coupon->code,
                $discount,
                $expiryDate
            );
            
            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Promotional SMS sent successfully',
                    'recipients_count' => count($recipients)
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to send promotional SMS: ' . $result['message']
                ], 500);
            }
        } catch (\Exception $e) {
            Log::error('Error sending promotional SMS: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while sending promotional SMS: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Send custom promotional SMS to customers
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function sendCustomSms(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:160',
            'recipient_type' => 'required|in:all,specific',
            'recipients' => 'required_if:recipient_type,specific|array',
            'recipients.*' => 'required_if:recipient_type,specific|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Get recipients based on type
            $recipients = [];
            if ($request->recipient_type === 'all') {
                // Get all users with phone numbers
                $recipients = User::whereNotNull('phone')
                    ->where('phone', '!=', '')
                    ->where('role', 'customer')
                    ->pluck('phone')
                    ->toArray();
            } else {
                // Use specific recipients
                $recipients = $request->recipients;
            }
            
            // Send custom promotional SMS
            $result = NotificationService::sendCustomPromotionalSms(
                $recipients,
                $request->message
            );
            
            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Custom promotional SMS sent successfully',
                    'recipients_count' => count($recipients)
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to send custom promotional SMS: ' . $result['message']
                ], 500);
            }
        } catch (\Exception $e) {
            Log::error('Error sending custom promotional SMS: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while sending custom promotional SMS: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get all customers with phone numbers for SMS campaigns
     *
     * @return \Illuminate\Http\Response
     */
    public function getCustomersWithPhones()
    {
        try {
            $customers = User::select('id', 'name', 'email', 'phone')
                ->whereNotNull('phone')
                ->where('phone', '!=', '')
                ->where('role', 'customer')
                ->get();
                
            return response()->json([
                'success' => true,
                'data' => $customers,
                'count' => $customers->count()
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching customers with phones: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while fetching customers: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get all available coupons for SMS campaigns
     *
     * @return \Illuminate\Http\Response
     */
    public function getAvailableCoupons()
    {
        try {
            $coupons = Coupon::where('is_active', true)
                ->where(function($query) {
                    $query->whereNull('expiry_date')
                        ->orWhere('expiry_date', '>=', now());
                })
                ->get();
                
            return response()->json([
                'success' => true,
                'data' => $coupons,
                'count' => $coupons->count()
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching available coupons: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while fetching coupons: ' . $e->getMessage()
            ], 500);
        }
    }
}
