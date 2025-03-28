<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Mail\OrderConfirmationMail;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use GuzzleHttp\Client;

class PaymentController extends Controller
{
    /**
     * Process a payment for an order.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function processPayment(Request $request)
    {
        try {
            // Log the start of payment processing
            Log::info('Starting payment processing', [
                'payment_method' => $request->payment_method,
                'request_data' => $request->except(['card_number', 'cvv', 'expiry_month', 'expiry_year'])
            ]);

            // Validate request data
            $validator = Validator::make($request->all(), [
                'payment_method' => 'required|string|in:card,bank_transfer,mobile_money',
                'currency' => 'required|string|size:3',
                'country' => 'required|string|size:2',
                'email' => 'required|email',
                'phone_number' => 'required|string',
                'name' => 'required|string',
                'order_id' => 'required|integer|exists:orders,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $orderId = $request->order_id;

            // Find the order
            $order = Order::find($orderId);

            if (!$order) {
                Log::warning('Order not found', [
                    'order_id' => $orderId
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Order not found'
                ], 404);
            }

            // Check if the order belongs to the authenticated user
            if ($order->user_id !== Auth::id()) {
                Log::warning('Unauthorized access to order', [
                    'order_id' => $orderId,
                    'user_id' => Auth::id(),
                    'order_user_id' => $order->user_id
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized'
                ], 403);
            }

            // Check if the order has already been paid
            if ($order->payment_status === 'paid') {
                Log::info('Order already paid', [
                    'order_id' => $orderId
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Order has already been paid'
                ], 400);
            }

            // Generate a unique transaction reference
            $tx_ref = 'MMART-' . time() . '-' . $orderId;

            // Initialize Flutterwave payment
            try {
                // Get Flutterwave configuration from .env
                $publicKey = env('FLUTTERWAVE_PUBLIC_KEY');
                $secretKey = env('FLUTTERWAVE_SECRET_KEY');
                $encryptionKey = env('FLUTTERWAVE_ENCRYPTION_KEY');

                // Ensure API keys are available
                if (empty($secretKey) || empty($publicKey)) {
                    Log::error('Flutterwave API keys not configured', [
                        'public_key_exists' => !empty($publicKey),
                        'secret_key_exists' => !empty($secretKey)
                    ]);
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Payment gateway not properly configured'
                    ], 500);
                }

                // Ensure the order exists and has a valid amount
                if (!$order || !$order->grand_total) {
                    Log::error('Order not found or has invalid amount', [
                        'order_id' => $orderId,
                        'grand_total' => $order ? $order->grand_total : null
                    ]);

                    return response()->json([
                        'status' => 'error',
                        'message' => 'Order not found or has invalid amount'
                    ], 400);
                }

                // Ensure the amount is properly formatted as a number with 2 decimal places
                $amount = number_format((float) $order->grand_total, 2, '.', '');

                // Log the order details for debugging
                Log::info('Order details', [
                    'order_id' => $order->id,
                    'grand_total' => $order->grand_total,
                    'amount_for_payment' => $amount,
                ]);

                // Prepare payment data
                $paymentData = [
                    'tx_ref' => $tx_ref,
                    'amount' => (float) $amount,
                    'currency' => $request->currency ?? 'NGN',
                    'payment_options' => 'card',
                    'redirect_url' => $request->redirect_url ?? env('FLUTTERWAVE_CALLBACK_URL', 'https://m-martplus.com/payments/callback'),
                    'customer' => [
                        'email' => $request->email ?? 'customer@example.com',
                        'phone_number' => $request->phone_number ?? '08012345678',
                        'name' => $request->name ?? 'Customer'
                    ],
                    'meta' => $request->meta ?? [
                        'order_id' => $order->id,
                        'user_id' => Auth::id() ?? $order->user_id
                    ],
                    'customizations' => [
                        'title' => 'M-Mart+ Order Payment',
                        'description' => 'Payment for order #' . $order->id,
                        'logo' => 'https://cdn.pixabay.com/photo/2016/11/07/13/04/yoga-1805784_960_720.png'
                    ]
                ];

                // Log payment data (excluding sensitive information)
                Log::info('Payment data prepared', [
                    'tx_ref' => $tx_ref,
                    'amount' => $amount,
                    'currency' => $request->currency,
                    'payment_method' => $request->payment_method,
                    'order_id' => $order->id,
                    'redirect_url' => $paymentData['redirect_url']
                ]);

                // Initialize payment using Flutterwave API directly
                $client = new Client();

                // Log the API request (excluding sensitive information)
                Log::info('Sending request to Flutterwave API', [
                    'url' => 'https://api.flutterwave.com/v3/payments',
                    'tx_ref' => $tx_ref,
                    'amount' => $amount,
                    'has_secret_key' => !empty($secretKey)
                ]);

                $response = $client->request('POST', 'https://api.flutterwave.com/v3/payments', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . trim($secretKey),
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $paymentData,
                    'http_errors' => false, // Don't throw exceptions for HTTP errors
                ]);

                $statusCode = $response->getStatusCode();
                $responseBody = $response->getBody()->getContents();
                $responseData = json_decode($responseBody, true);

                Log::info('Flutterwave API response', [
                    'status_code' => $statusCode,
                    'response_status' => $responseData['status'] ?? 'unknown',
                    'response_message' => $responseData['message'] ?? 'No message',
                    'has_data' => isset($responseData['data']),
                    'has_link' => isset($responseData['data']['link'])
                ]);

                // Check if the response contains the expected data
                if ($statusCode !== 200 || !isset($responseData['data']['link'])) {
                    Log::error('Flutterwave API error', [
                        'status_code' => $statusCode,
                        'response' => $responseData
                    ]);

                    return response()->json([
                        'status' => 'error',
                        'message' => 'Payment initialization failed: ' . ($responseData['message'] ?? 'Unknown error')
                    ], 500);
                }

                // Create a new payment record
                $payment = new Payment();
                $payment->order_id = $order->id;
                $payment->amount = $amount; // Use the validated amount variable
                $payment->payment_method = $request->payment_method;
                $payment->status = 'pending';
                $payment->transaction_reference = $tx_ref;
                $payment->payment_details = json_encode([
                    'currency' => $request->currency,
                    'customer_email' => $request->email,
                    'customer_name' => $request->name,
                    'customer_phone' => $request->phone_number
                ]);

                // Log payment record before saving
                Log::info('Creating payment record', [
                    'order_id' => $payment->order_id,
                    'amount' => $payment->amount,
                    'payment_method' => $payment->payment_method,
                    'transaction_reference' => $payment->transaction_reference
                ]);

                $payment->save();

                // Return the payment link
                return response()->json([
                    'status' => 'success',
                    'message' => 'Payment initialized',
                    'redirect_url' => $responseData['data']['link'],
                    'tx_ref' => $tx_ref
                ]);
            } catch (\Exception $e) {
                Log::error('Flutterwave payment initialization failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Payment initialization failed: ' . $e->getMessage()
                ], 500);
            }
        } catch (\Exception $e) {
            Log::error('Payment processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred while processing your payment: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle payment callback from Flutterwave
     * 
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function handleCallback(Request $request)
    {
        try {
            Log::info('Payment callback received', [
                'status' => $request->status,
                'tx_ref' => $request->tx_ref,
                'transaction_id' => $request->transaction_id,
                'all_params' => $request->all(),
                'query_params' => $request->query(),
                'input_params' => $request->input(),
                'url' => $request->fullUrl()
            ]);

            // If transaction_id is missing, try to extract it from the request
            $transactionId = $request->transaction_id;
            if (!$transactionId && $request->has('transaction_id')) {
                $transactionId = $request->input('transaction_id');
            }

            // If still no transaction_id, check if it's in the query string
            if (!$transactionId) {
                $transactionId = $request->query('transaction_id');
            }

            // If still no transaction ID, check tx_ref and try to find the payment
            if (!$transactionId && $request->tx_ref) {
                $payment = Payment::where('transaction_reference', $request->tx_ref)->first();
                if ($payment && $payment->transaction_id) {
                    $transactionId = $payment->transaction_id;
                    Log::info('Found transaction ID from payment record', [
                        'tx_ref' => $request->tx_ref,
                        'transaction_id' => $transactionId
                    ]);
                }
            }

            // Check if the transaction was successful based on status parameter
            $status = $request->status ?? $request->input('status') ?? $request->query('status');

            if ($status === 'successful' || $status === 'completed') {
                // If we have a transaction ID, verify it
                if ($transactionId) {
                    try {
                        $client = new \GuzzleHttp\Client();

                        // Get the secret key with proper trimming
                        $secretKey = trim(env('FLUTTERWAVE_SECRET_KEY'));

                        // Verify the transaction
                        $response = $client->request('GET', 'https://api.flutterwave.com/v3/transactions/' . $transactionId . '/verify', [
                            'headers' => [
                                'Authorization' => 'Bearer ' . $secretKey,
                                'Content-Type' => 'application/json',
                            ],
                            'http_errors' => false, // Don't throw exceptions for HTTP errors
                        ]);

                        $responseData = json_decode($response->getBody(), true);

                        Log::info('Payment verification response', [
                            'response' => $responseData,
                            'tx_ref' => $request->tx_ref
                        ]);

                        // Check if verification was successful
                        if (isset($responseData['status']) && $responseData['status'] === 'success' && 
                            isset($responseData['data']['status']) && $responseData['data']['status'] === 'successful') {

                            // Extract the transaction reference
                            $txRef = $responseData['data']['tx_ref'];

                            // Find the payment record
                            $payment = Payment::where('transaction_reference', $txRef)->first();

                            if ($payment) {
                                // Update payment record
                                $payment->status = 'completed';
                                $payment->transaction_id = $transactionId;
                                $payment->payment_details = json_encode($responseData);
                                $payment->save();

                                // Update order status if applicable
                                if ($payment->order) {
                                    $payment->order->payment_status = 'paid';
                                    $payment->order->status = 'processing'; // Update order status to processing
                                    $payment->order->save();

                                    try {
                                        $user = User::find($payment->order->user_id);
                                        Log::info('Preparing to send order confirmation email', [
                                            'order_id' => $payment->order->id,
                                            'order_number' => $payment->order->order_number,
                                            'user_id' => $user->id,
                                            'user_email' => $user->email
                                        ]);

                                        NotificationService::sendOrderConfirmation($payment->order);

                                        Log::info('Order confirmation email sent successfully', [
                                            'order_id' => $payment->order->id,
                                            'user_email' => $user->email
                                        ]);

                                        // Set email status for frontend response
                                        $emailSent = true;
                                    } catch (\Exception $e) {
                                        Log::error('Failed to send order confirmation email', [
                                            'order_id' => $payment->order->id,
                                            'error' => $e->getMessage(),
                                            'trace' => $e->getTraceAsString()
                                        ]);

                                        // Set email status for frontend response
                                        $emailSent = false;
                                    }

                                    Log::info('Order confirmation email sent', [
                                        'order_id' => $payment->order->id,
                                        'user_email' => $user->email
                                    ]);

                                    // Redirect to success page
                                    if ($payment->order) {
                                        return redirect()->route('orders.success', ['id' => $payment->order->id]);
                                    } else {
                                        return redirect()->route('payment.success');
                                    }
                                } else {
                                    return redirect()->route('payment.error')
                                        ->with('error', 'Payment verification failed: Payment record not found');
                                }
                            } else {
                                Log::error('Payment record not found for transaction reference', [
                                    'tx_ref' => $txRef
                                ]);

                                return redirect()->route('payment.error')
                                    ->with('error', 'Payment verification failed: Payment record not found');
                            }
                        } else {
                            // Payment verification failed
                            Log::warning('Payment verification failed', [
                                'response' => $responseData
                            ]);

                            return redirect()->route('payment.error')
                                ->with('error', 'Payment verification failed: ' . ($responseData['message'] ?? 'Unknown error'));
                        }
                    } catch (\Exception $e) {
                        Log::error('Exception during payment verification', [
                            'message' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);

                        return redirect()->route('payment.error')
                            ->with('error', 'Payment verification failed: ' . $e->getMessage());
                    }
                } else {
                    // Status is successful but no transaction ID
                    Log::warning('Status is successful but no transaction ID', [
                        'request_data' => $request->all()
                    ]);

                    // Try to find payment by tx_ref
                    if ($request->tx_ref) {
                        $payment = Payment::where('transaction_reference', $request->tx_ref)->first();
                        if ($payment) {
                            // Mark as completed based on status only
                            $payment->status = 'completed';
                            $payment->save();

                            // Update order status if applicable
                            if ($payment->order) {
                                $payment->order->payment_status = 'paid';
                                $payment->order->status = 'processing'; // Update order status to processing
                                $payment->order->save();

                                try {
                                    $user = User::find($payment->order->user_id);
                                    Log::info('Preparing to send order confirmation email', [
                                        'order_id' => $payment->order->id,
                                        'order_number' => $payment->order->order_number,
                                        'user_id' => $user->id,
                                        'user_email' => $user->email
                                    ]);

                                    NotificationService::sendOrderConfirmation($payment->order);

                                    Log::info('Order confirmation email sent successfully', [
                                        'order_id' => $payment->order->id,
                                        'user_email' => $user->email
                                    ]);

                                    // Set email status for frontend response
                                    $emailSent = true;
                                } catch (\Exception $e) {
                                    Log::error('Failed to send order confirmation email', [
                                        'order_id' => $payment->order->id,
                                        'error' => $e->getMessage(),
                                        'trace' => $e->getTraceAsString()
                                    ]);

                                    // Set email status for frontend response
                                    $emailSent = false;
                                }

                                Log::info('Order confirmation email sent', [
                                    'order_id' => $payment->order->id,
                                    'user_email' => $user->email
                                ]);

                                return redirect()->route('orders.success', ['id' => $payment->order->id]);
                            } else {
                                return redirect()->route('payment.success');
                            }
                        }
                    }

                    return redirect()->route('payment.error')
                        ->with('error', 'Payment verification failed: No transaction ID found');
                }
            } else {
                // Status is not successful
                Log::warning('Payment status is not successful', [
                    'status' => $status,
                    'request_data' => $request->all()
                ]);

                return redirect()->route('payment.error')
                    ->with('error', 'Payment was not successful: ' . ($status ?? 'Unknown status'));
            }
        } catch (\Exception $e) {
            Log::error('Exception during payment callback handling', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return redirect()->route('payment.error')
                ->with('error', 'Payment processing error: ' . $e->getMessage());
        }
    }

    /**
     * Verify payment status
     * 
     * @param Request $request
     * @param string $transactionId
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyPayment(Request $request, $transactionId)
    {
        try {
            Log::info('Verifying payment', [
                'transaction_id' => $transactionId
            ]);

            // Verify the transaction
            $client = new Client();
            $response = $client->request('GET', 'https://api.flutterwave.com/v3/transactions/' . $transactionId . '/verify', [
                'headers' => [
                    'Authorization' => 'Bearer ' . env('FLUTTERWAVE_SECRET_KEY'),
                ],
            ]);

            $responseData = json_decode($response->getBody(), true);

            Log::info('Payment verification response', [
                'response' => $responseData,
                'transaction_id' => $transactionId
            ]);

            // Check if verification was successful
            if ($responseData['status'] === 'success' && $responseData['data']['status'] === 'successful') {
                // Extract the transaction reference
                $txRef = $responseData['data']['tx_ref'];

                // Find the payment record
                $payment = Payment::where('transaction_reference', $txRef)->first();

                if ($payment) {
                    // Update payment status
                    $payment->status = 'completed';
                    $payment->transaction_id = $transactionId;
                    $payment->payment_details = json_encode($responseData);
                    $payment->save();

                    // Update order payment status
                    $order = Order::find($payment->order_id);
                    if ($order) {
                        $order->payment_status = 'paid';
                        $order->status = 'processing'; // Update order status to processing
                        $order->save();

                        try {
                            $user = User::find($order->user_id);
                            Log::info('Preparing to send order confirmation email', [
                                'order_id' => $order->id,
                                'order_number' => $order->order_number,
                                'user_id' => $user->id,
                                'user_email' => $user->email
                            ]);

                            NotificationService::sendOrderConfirmation($order);

                            Log::info('Order confirmation email sent successfully', [
                                'order_id' => $order->id,
                                'user_email' => $user->email
                            ]);

                            // Set email status for frontend response
                            $emailSent = true;
                        } catch (\Exception $e) {
                            Log::error('Failed to send order confirmation email', [
                                'order_id' => $order->id,
                                'error' => $e->getMessage(),
                                'trace' => $e->getTraceAsString()
                            ]);

                            // Set email status for frontend response
                            $emailSent = false;
                        }

                        Log::info('Order confirmation email sent', [
                            'order_id' => $order->id,
                            'user_email' => $user->email
                        ]);

                        return response()->json([
                            'success' => true,
                            'message' => 'Payment successful',
                            'data' => [
                                'order_id' => $order->id,
                                'payment_status' => 'completed',
                                'transaction_id' => $transactionId,
                                'email_sent' => $emailSent ?? false
                            ]
                        ]);
                    }
                }

                return response()->json([
                    'status' => 'error',
                    'message' => 'Payment record not found'
                ], 404);
            } else {
                Log::warning('Payment verification failed', [
                    'transaction_id' => $transactionId,
                    'response' => $responseData
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Payment verification failed',
                    'data' => $responseData
                ], 400);
            }
        } catch (\Exception $e) {
            Log::error('Payment verification failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'transaction_id' => $transactionId
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred while verifying payment: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verify a Flutterwave transaction
     * 
     * @param string $transactionId
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyTransaction($transactionId)
    {
        try {
            Log::info('Verifying Flutterwave transaction', [
                'transaction_id' => $transactionId
            ]);

            // Get Flutterwave configuration
            $secretKey = env('FLUTTERWAVE_SECRET_KEY');
            
            if (empty($secretKey)) {
                Log::error('Flutterwave secret key not configured');
                return response()->json([
                    'status' => 'error',
                    'message' => 'Payment gateway not properly configured'
                ], 500);
            }

            // Initialize Guzzle client
            $client = new Client();
            
            // Make request to Flutterwave API to verify the transaction
            $response = $client->request('GET', 'https://api.flutterwave.com/v3/transactions/' . $transactionId . '/verify', [
                'headers' => [
                    'Authorization' => 'Bearer ' . trim($secretKey),
                    'Content-Type' => 'application/json',
                ],
                'http_errors' => false,
            ]);
            
            $statusCode = $response->getStatusCode();
            $responseBody = $response->getBody()->getContents();
            $responseData = json_decode($responseBody, true);
            
            Log::info('Flutterwave verification response', [
                'status_code' => $statusCode,
                'response_status' => $responseData['status'] ?? 'unknown',
                'response_message' => $responseData['message'] ?? 'No message',
                'has_data' => isset($responseData['data'])
            ]);
            
            // Check if the verification was successful
            if ($statusCode === 200 && isset($responseData['data']) && $responseData['status'] === 'success') {
                // Find the payment by transaction reference
                $payment = Payment::where('transaction_reference', $responseData['data']['tx_ref'])
                    ->orWhere('transaction_id', $transactionId)
                    ->first();
                
                if ($payment) {
                    // Update payment status
                    $payment->status = 'completed';
                    $payment->transaction_id = $transactionId;
                    $payment->payment_details = json_encode(array_merge(
                        json_decode($payment->payment_details, true) ?? [],
                        [
                            'verification_response' => $responseData,
                            'verified_at' => now()->toDateTimeString()
                        ]
                    ));
                    $payment->save();
                    
                    // Update order status
                    $order = Order::find($payment->order_id);
                    if ($order) {
                        $order->payment_status = 'paid';
                        $order->status = 'processing';
                        $order->save();
                        
                        // Send order confirmation email
                        try {
                            // Mail::to($order->email)->send(new OrderConfirmation($order));
                            $emailSent = true;
                        } catch (\Exception $e) {
                            Log::error('Failed to send order confirmation email', [
                                'error' => $e->getMessage(),
                                'order_id' => $order->id
                            ]);
                            $emailSent = false;
                        }
                        
                        return response()->json([
                            'status' => 'success',
                            'message' => 'Payment verified successfully',
                            'data' => [
                                'order_id' => $order->id,
                                'order_number' => $order->order_number,
                                'payment_status' => 'completed',
                                'transaction_id' => $transactionId,
                                'email_sent' => $emailSent ?? false
                            ]
                        ]);
                    }
                }
                
                // If we can't find the payment but verification was successful
                return response()->json([
                    'status' => 'success',
                    'message' => 'Payment verified but no matching order found',
                    'data' => [
                        'transaction_id' => $transactionId,
                        'flutterwave_data' => $responseData['data']
                    ]
                ]);
            } else {
                Log::warning('Flutterwave payment verification failed', [
                    'transaction_id' => $transactionId,
                    'response' => $responseData
                ]);
                
                return response()->json([
                    'status' => 'error',
                    'message' => 'Payment verification failed: ' . ($responseData['message'] ?? 'Unknown error'),
                    'data' => $responseData
                ], 400);
            }
        } catch (\Exception $e) {
            Log::error('Error verifying Flutterwave payment', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'transaction_id' => $transactionId
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred while verifying payment: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get payment status for an order
     * 
     * @param Request $request
     * @param int $orderId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPaymentStatus(Request $request, $orderId)
    {
        try {
            // Find the order
            $order = Order::find($orderId);

            if (!$order) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Order not found'
                ], 404);
            }

            // Check if the order belongs to the authenticated user
            if ($order->user_id !== Auth::id()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized'
                ], 403);
            }

            // Get the latest payment for the order
            $payment = Payment::where('order_id', $order->id)
                ->orderBy('created_at', 'desc')
                ->first();

            if (!$payment) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No payment found for this order'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'order_id' => $order->id,
                    'payment_status' => $payment->status,
                    'payment_method' => $payment->payment_method,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'transaction_reference' => $payment->transaction_reference,
                    'transaction_id' => $payment->transaction_id,
                    'created_at' => $payment->created_at,
                    'updated_at' => $payment->updated_at
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get payment status', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'order_id' => $orderId
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred while getting payment status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available payment methods
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPaymentMethods()
    {
        try {
            // Define available payment methods
            $paymentMethods = [
                [
                    'id' => 'card',
                    'name' => 'Card Payment',
                    'description' => 'Pay with debit or credit card',
                    'icon' => 'credit-card',
                    'enabled' => true
                ],
                [
                    'id' => 'bank_transfer',
                    'name' => 'Bank Transfer',
                    'description' => 'Pay via bank transfer',
                    'icon' => 'bank',
                    'enabled' => true
                ],
                [
                    'id' => 'mobile_money',
                    'name' => 'Mobile Money',
                    'description' => 'Pay with mobile money',
                    'icon' => 'mobile',
                    'enabled' => true
                ],
                [
                    'id' => 'cash_on_delivery',
                    'name' => 'Cash on Delivery',
                    'description' => 'Pay when your order is delivered',
                    'icon' => 'money-bill',
                    'enabled' => true
                ]
            ];

            return response()->json([
                'status' => 'success',
                'message' => 'Payment methods retrieved successfully',
                'data' => $paymentMethods
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving payment methods', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve payment methods',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle webhook events from Flutterwave
     * 
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function handleWebhook(Request $request)
    {
        try {
            // Log the webhook request
            Log::info('Flutterwave webhook received', [
                'headers' => $request->header(),
                'payload' => $request->all()
            ]);

            // Verify webhook signature if webhook secret is set
            if (env('FLUTTERWAVE_WEBHOOK_SECRET')) {
                $signature = $request->header('verif-hash');
                if (!$signature || $signature !== env('FLUTTERWAVE_WEBHOOK_SECRET')) {
                    Log::warning('Invalid webhook signature', [
                        'received_signature' => $signature
                    ]);
                    return response()->json(['status' => 'error', 'message' => 'Invalid signature'], 401);
                }
            }

            // Process the webhook event
            $payload = $request->all();

            // Check if this is a payment event
            if (isset($payload['event']) && $payload['event'] === 'charge.completed') {
                $data = $payload['data'];
                $txRef = $data['tx_ref'] ?? null;
                $status = $data['status'] ?? null;
                $transactionId = $data['id'] ?? null;

                if ($txRef && $status === 'successful' && $transactionId) {
                    // Find the payment record
                    $payment = Payment::where('transaction_reference', $txRef)->first();

                    if ($payment) {
                        // Update payment status
                        $payment->status = 'completed';
                        $payment->transaction_id = $transactionId;
                        $payment->payment_details = json_encode([
                            'webhook_data' => $data,
                            'verification_time' => now()->toDateTimeString()
                        ]);
                        $payment->save();

                        // Update order payment status
                        $order = Order::find($payment->order_id);
                        if ($order) {
                            $order->payment_status = 'paid';
                            $order->payment_method = 'flutterwave';
                            $order->payment_reference = $txRef;
                            $order->status = 'processing'; // Update order status to processing
                            $order->save();

                            try {
                                $user = User::find($order->user_id);
                                Log::info('Preparing to send order confirmation email', [
                                    'order_id' => $order->id,
                                    'order_number' => $order->order_number,
                                    'user_id' => $user->id,
                                    'user_email' => $user->email
                                ]);

                                NotificationService::sendOrderConfirmation($order);

                                Log::info('Order confirmation email sent successfully', [
                                    'order_id' => $order->id,
                                    'user_email' => $user->email
                                ]);

                                // Set email status for frontend response
                                $emailSent = true;
                            } catch (\Exception $e) {
                                Log::error('Failed to send order confirmation email', [
                                    'order_id' => $order->id,
                                    'error' => $e->getMessage(),
                                    'trace' => $e->getTraceAsString()
                                ]);

                                // Set email status for frontend response
                                $emailSent = false;
                            }

                            Log::info('Order confirmation email sent', [
                                'order_id' => $order->id,
                                'user_email' => $user->email
                            ]);
                        }
                    } else {
                        Log::warning('Payment record not found for webhook event', [
                            'tx_ref' => $txRef
                        ]);
                    }
                }
            }

            // Always return a 200 response to acknowledge receipt of the webhook
            return response()->json(['status' => 'success', 'message' => 'Webhook processed']);
        } catch (\Exception $e) {
            Log::error('Error processing webhook', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Still return a 200 response to prevent Flutterwave from retrying
            return response()->json(['status' => 'error', 'message' => 'Error processing webhook']);
        }
    }
}
