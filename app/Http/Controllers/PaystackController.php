<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaystackController extends Controller
{
    /**
     * Initialize a Paystack payment with intelligent amount detection
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function initializePayment(Request $request)
    {
        try {
            // Validate request
            $request->validate([
                'email' => 'required|email',
                'amount' => 'required|numeric',
                'callback_url' => 'required|url',
                'metadata' => 'required|array'
            ]);

            // Get the original amount
            $originalAmount = $request->amount;
            
            // Log the original amount for debugging
            Log::info('Paystack payment - original amount received', [
                'original_amount' => $originalAmount,
                'formatted' => number_format($originalAmount, 2)
            ]);

            // Intelligently detect and convert the amount format
            $koboAmount = $this->detectAndConvertToKobo($originalAmount);
            
            // Log the converted amount
            Log::info('Paystack payment - converted amount', [
                'kobo_amount' => $koboAmount,
                'naira_equivalent' => number_format($koboAmount / 100, 2)
            ]);

            // Prepare Paystack payload
            $payload = [
                'amount' => $koboAmount,
                'email' => $request->email,
                'currency' => 'NGN',
                'reference' => 'MMART-' . time() . '-' . uniqid(),
                'callback_url' => $request->callback_url,
                'metadata' => $request->metadata
            ];

            // Initialize Paystack transaction
            $response = $this->makePaystackRequest('POST', '/transaction/initialize', $payload);

            // Check if the request was successful
            if (!$response['status']) {
                Log::error('Paystack initialization failed', [
                    'payload' => $payload,
                    'response' => $response
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Payment initialization failed: ' . ($response['message'] ?? 'Unknown error')
                ], 400);
            }

            // Return success response
            return response()->json([
                'status' => 'success',
                'message' => 'Payment initialized successfully',
                'data' => $response['data']
            ]);
        } catch (\Exception $e) {
            Log::error('Paystack initialization exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Payment initialization failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Intelligently detect the amount format and convert to kobo
     * 
     * @param float|int $amount The amount to convert
     * @return int The amount in kobo
     */
    private function detectAndConvertToKobo($amount)
    {
        // Get expected naira and kobo values for debugging
        $expectedNairaValue = $amount;
        $expectedKoboValue = $amount * 100;
        
        // Check if the amount is likely already in kobo
        // If the amount is large (over 10000) and has no decimal places, it's likely already in kobo
        $isLikelyKobo = $amount > 10000 && floor($amount) == $amount;
        
        // Check if the amount looks like naira with decimal places
        $hasDecimalPlaces = floor($amount) != $amount;
        
        // Check if the amount looks like naira stored as an integer (e.g., 2485288 for 24,852.88)
        // This is harder to detect, but we can check if dividing by 100 gives a more reasonable value
        $nairaValueIfInteger = $amount / 100;
        $isLikelyNairaAsInteger = $amount > 100000 && !$hasDecimalPlaces;
        
        // Log the detection process
        Log::info('Paystack amount format detection', [
            'original_amount' => $amount,
            'has_decimal_places' => $hasDecimalPlaces,
            'is_likely_kobo' => $isLikelyKobo,
            'is_likely_naira_as_integer' => $isLikelyNairaAsInteger,
            'naira_value_if_integer' => $nairaValueIfInteger
        ]);
        
        // Make the conversion decision
        if ($hasDecimalPlaces) {
            // If it has decimal places, it's likely in naira with decimal point
            // Convert to kobo by multiplying by 100 and rounding
            $koboAmount = round($amount * 100);
            Log::info('Detected as naira with decimal places', [
                'original' => $amount,
                'converted_to_kobo' => $koboAmount
            ]);
            return $koboAmount;
        } 
        else if ($isLikelyNairaAsInteger) {
            // If it's likely naira stored as an integer without decimal point
            // Just use it as is, as it's already in the right format for kobo
            Log::info('Detected as naira stored as integer', [
                'original' => $amount,
                'used_as_kobo' => $amount
            ]);
            return $amount;
        }
        else if ($isLikelyKobo) {
            // If it's likely already in kobo, use it as is
            Log::info('Detected as already in kobo', [
                'original' => $amount,
                'used_as_is' => $amount
            ]);
            return $amount;
        }
        else {
            // Default case: assume it's in naira and convert to kobo
            $koboAmount = round($amount * 100);
            Log::info('Format unclear, defaulting to naira conversion', [
                'original' => $amount,
                'converted_to_kobo' => $koboAmount
            ]);
            return $koboAmount;
        }
    }

    /**
     * Verify a Paystack transaction
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyPayment(Request $request)
    {
        try {
            // Validate request
            $request->validate([
                'reference' => 'required|string'
            ]);

            $reference = $request->reference;

            // Verify Paystack transaction
            $response = $this->makePaystackRequest('GET', "/transaction/verify/{$reference}");

            // Check if the verification was successful
            if (!$response['status']) {
                Log::error('Paystack verification failed', [
                    'reference' => $reference,
                    'response' => $response
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Payment verification failed: ' . ($response['message'] ?? 'Unknown error')
                ], 400);
            }

            // Check if the transaction was successful
            $transactionData = $response['data'];
            $paymentStatus = $transactionData['status'];

            if ($paymentStatus !== 'success') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Payment was not successful: ' . $transactionData['gateway_response'],
                    'data' => $transactionData
                ], 400);
            }

            // Extract order data from metadata
            $metadata = $transactionData['metadata'] ?? [];
            
            // Log the metadata for debugging
            Log::info('Paystack metadata received:', [
                'reference' => $reference,
                'metadata' => $metadata
            ]);
            
            // Try to get order_id directly from metadata (new approach)
            $orderId = $metadata['order_id'] ?? null;
            
            // Fallback to old approach if order_id not found directly
            if (!$orderId && isset($metadata['order_data'])) {
                $orderData = $metadata['order_data'] ?? null;
                $orderId = $orderData['id'] ?? null;
                
                if (!$orderData) {
                    Log::error('Order data not found in Paystack metadata', [
                        'reference' => $reference,
                        'metadata' => $metadata
                    ]);
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Order data not found in payment metadata'
                    ], 400);
                }
            }
            
            // Update order status
            $order = Order::find($orderId);

            if (!$order) {
                // Try to find the order by reference
                $order = Order::where('payment_reference', $reference)->first();
                
                if (!$order) {
                    Log::error('Order not found for Paystack payment', [
                        'order_id' => $orderId,
                        'reference' => $reference,
                        'transaction_data' => $transactionData
                    ]);
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Order not found'
                    ], 404);
                } else {
                    Log::info('Order found by payment reference instead of ID', [
                        'order_id' => $order->id,
                        'reference' => $reference
                    ]);
                }
            }

            // Update order status
            $order->status = Order::STATUS_PROCESSING;
            $order->payment_status = 'paid';
            $order->payment_reference = $reference;
            $order->save();

            // Create payment record - ensure amount is converted from kobo to naira
            Payment::create([
                'order_id' => $order->id,
                'amount' => $transactionData['amount'] / 100, // Convert from kobo to naira
                'payment_method' => 'paystack',
                'payment_reference' => $reference,
                'status' => 'completed',
                'transaction_data' => json_encode($transactionData)
            ]);

            // Return success response
            return response()->json([
                'status' => 'success',
                'message' => 'Payment verified successfully',
                'data' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'payment_status' => $order->payment_status,
                    'transaction_data' => $transactionData
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Paystack verification exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Payment verification failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle Paystack webhook
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function handleWebhook(Request $request)
    {
        // Verify webhook signature
        $signature = $request->header('x-paystack-signature');
        $payload = $request->getContent();
        
        if (!$this->verifyWebhookSignature($signature, $payload)) {
            Log::error('Invalid Paystack webhook signature');
            return response()->json(['status' => 'error', 'message' => 'Invalid signature'], 400);
        }

        // Parse webhook payload
        $event = json_decode($payload, true);
        $eventType = $event['event'] ?? '';
        
        // Handle different event types
        switch ($eventType) {
            case 'charge.success':
                return $this->handleSuccessfulCharge($event['data']);
            default:
                Log::info('Unhandled Paystack webhook event', ['event' => $eventType]);
                return response()->json(['status' => 'success', 'message' => 'Webhook received']);
        }
    }

    /**
     * Handle successful charge event
     *
     * @param array $data
     * @return \Illuminate\Http\JsonResponse
     */
    private function handleSuccessfulCharge($data)
    {
        try {
            $reference = $data['reference'] ?? null;
            
            if (!$reference) {
                Log::error('Reference not found in Paystack webhook data');
                return response()->json(['status' => 'error', 'message' => 'Reference not found'], 400);
            }
            
            // Extract metadata
            $metadata = $data['metadata'] ?? [];
            $orderData = $metadata['order_data'] ?? null;
            
            if (!$orderData) {
                Log::error('Order data not found in Paystack webhook metadata', [
                    'reference' => $reference,
                    'metadata' => $metadata
                ]);
                return response()->json(['status' => 'error', 'message' => 'Order data not found'], 400);
            }
            
            // Find and update order
            $orderId = $orderData['id'] ?? null;
            $order = Order::find($orderId);
            
            if (!$order) {
                Log::error('Order not found for Paystack webhook', [
                    'order_id' => $orderId,
                    'reference' => $reference
                ]);
                return response()->json(['status' => 'error', 'message' => 'Order not found'], 404);
            }
            
            // Update order if not already paid
            if ($order->payment_status !== 'paid') {
                $order->status = Order::STATUS_PROCESSING;
                $order->payment_status = 'paid';
                $order->payment_reference = $reference;
                $order->save();
                
                // Create payment record if it doesn't exist - ensure amount is converted from kobo to naira
                Payment::firstOrCreate(
                    ['payment_reference' => $reference],
                    [
                        'order_id' => $order->id,
                        'amount' => $data['amount'] / 100, // Convert from kobo to naira
                        'payment_method' => 'paystack',
                        'status' => 'completed',
                        'transaction_data' => json_encode($data)
                    ]
                );
                
                Log::info('Order updated via Paystack webhook', [
                    'order_id' => $order->id,
                    'reference' => $reference
                ]);
            }
            
            return response()->json(['status' => 'success', 'message' => 'Webhook processed successfully']);
        } catch (\Exception $e) {
            Log::error('Error processing Paystack webhook', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['status' => 'error', 'message' => 'Error processing webhook'], 500);
        }
    }

    /**
     * Verify webhook signature
     *
     * @param string $signature
     * @param string $payload
     * @return bool
     */
    private function verifyWebhookSignature($signature, $payload)
    {
        if (!$signature) {
            return false;
        }
        
        $secretKey = env('PAYSTACK_SECRET_KEY');
        $hash = hash_hmac('sha512', $payload, $secretKey);
        
        return hash_equals($hash, $signature);
    }

    /**
     * Make a request to the Paystack API
     *
     * @param string $method
     * @param string $endpoint
     * @param array $data
     * @return array
     */
    private function makePaystackRequest($method, $endpoint, $data = [])
    {
        $url = env('PAYSTACK_PAYMENT_URL', 'https://api.paystack.co') . $endpoint;
        $secretKey = env('PAYSTACK_SECRET_KEY');
        
        $headers = [
            'Authorization: Bearer ' . $secretKey,
            'Content-Type: application/json',
            'Cache-Control: no-cache'
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            Log::error('Paystack API request failed', [
                'error' => $error,
                'endpoint' => $endpoint
            ]);
            return [
                'status' => false,
                'message' => 'API request failed: ' . $error
            ];
        }
        
        return json_decode($response, true);
    }
}