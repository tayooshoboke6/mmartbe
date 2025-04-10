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
                'request_data' => $request->except(['card_number', 'cvv', 'expiry_month', 'expiry_year']),
                'action' => $request->action
            ]);

            // Log the full request data
            Log::info('Full payment request data', [
                'request_data' => $request->all()
            ]);
            
            // Check if this is just a payment method update
            if ($request->action === 'update_method') {
                // Only validate the payment method for updates
                $validator = Validator::make($request->all(), [
                    'payment_method' => 'required|string|in:bank_transfer,paystack,flutterwave,cash_on_delivery',
                ]);
                
                if ($validator->fails()) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Validation failed',
                        'errors' => $validator->errors()
                    ], 422);
                }
                
                $orderId = $request->route('order');
                
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
                
                // Update the payment method
                $order->payment_method = $request->payment_method;
                $order->save();
                
                Log::info('Payment method updated successfully', [
                    'order_id' => $orderId,
                    'payment_method' => $request->payment_method
                ]);
                
                return response()->json([
                    'status' => 'success',
                    'message' => 'Payment method updated successfully',
                    'data' => [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'payment_method' => $order->payment_method
                    ]
                ]);
            }
            
            // For regular payment processing, validate all required fields
            $validator = Validator::make($request->all(), [
                'payment_method' => 'required|string|in:bank_transfer,paystack,flutterwave,cash_on_delivery',
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

                // Prepare payment data
                $paymentData = [
                    'tx_ref' => $tx_ref,
                    'amount' => (string) $order->grand_total,
                    'currency' => 'NGN',
                    'payment_options' => 'card, mobilemoney, ussd',
                    'redirect_url' => $request->redirect_url ?? env('FLUTTERWAVE_CALLBACK_URL', 'http://localhost:3000/payments/callback'),
                    'customer' => [
                        'email' => $request->email,
                        'phonenumber' => $request->phone_number,
                        'name' => $request->name,
                        'country' => $request->country
                    ],
                    'meta' => [
                        'order_id' => $order->id,
                        'user_id' => Auth::id() ?? $order->user_id,
                        'price' => (string) $order->grand_total
                    ],
                    'customizations' => [
                        'title' => 'M-Mart+ Order Payment',
                        'description' => 'Payment for order #' . $order->id
                        // No logo specified - will use the one from Flutterwave account
                    ]
                ];

                // Log the exact data being sent to Flutterwave
                Log::info('Data being sent to Flutterwave', [
                    'payload' => $paymentData,
                    'headers' => [
                        'Authorization' => 'Bearer ' . substr($secretKey, 0, 10) . '...',
                        'Content-Type' => 'application/json'
                    ]
                ]);

                // Initialize payment using Flutterwave API directly
                $client = new Client();

                // Log the API request (excluding sensitive information)
                Log::info('Sending request to Flutterwave API', [
                    'url' => 'https://api.flutterwave.com/v3/payments',
                    'tx_ref' => $tx_ref,
                    'amount' => $order->grand_total,
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

                // Log the complete response from Flutterwave
                Log::info('Complete Flutterwave response', [
                    'status_code' => $statusCode,
                    'response_body' => $responseData,
                    'headers' => $response->getHeaders()
                ]);

                // Check if the response contains the expected data
                if ($statusCode !== 200 || !isset($responseData['data']['link'])) {
                    Log::error('Flutterwave payment initialization failed', [
                        'status_code' => $statusCode,
                        'response' => $responseData,
                        'request_data' => $paymentData
                    ]);

                    return response()->json([
                        'status' => 'error',
                        'message' => 'Payment initialization failed: ' . ($responseData['message'] ?? 'Unknown error')
                    ], 500);
                }

                // Create a new payment record
                $payment = new Payment();
                $payment->order_id = $order->id;
                $payment->amount = $order->grand_total;
                $payment->payment_method = $request->payment_method;
                $payment->status = 'pending';
                $payment->transaction_reference = $tx_ref;
                $payment->payment_details = json_encode([
                    'currency' => 'NGN',
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
                        
                        // Cart will be cleared in the frontend after successful payment verification
                        Log::info('Cart will be cleared in frontend after Flutterwave payment verification', [
                            'order_id' => $order->id,
                            'order_number' => $order->order_number,
                            'transaction_id' => $transactionId
                        ]);

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
    /**
     * Initialize a Paystack payment
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function initializePaystackPayment(Request $request)
    {
        try {
            Log::info('Initializing Paystack payment', [
                'request_data' => $request->except(['metadata'])
            ]);
            
            // Validate request
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
                'amount' => 'required|numeric',
                'order_id' => 'required',
                'callback_url' => 'required|url',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            // Find the order - handle both numeric IDs and order numbers
            $orderId = $request->order_id;
            $order = null;
            
            // Check if it's a numeric ID
            if (is_numeric($orderId)) {
                $order = Order::find($orderId);
            } else {
                // Try to find by order number
                $order = Order::where('order_number', $orderId)->first();
            }
            
            if (!$order) {
                Log::warning('Order not found', [
                    'order_id' => $request->order_id
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Order not found'
                ], 404);
            }
            
            // Get Paystack configuration
            $secretKey = env('PAYSTACK_SECRET_KEY');
            
            if (empty($secretKey)) {
                Log::error('Paystack API key not configured');
                return response()->json([
                    'status' => 'error',
                    'message' => 'Payment gateway not properly configured'
                ], 500);
            }
            
            // Convert amount to kobo (Paystack uses the smallest currency unit)
            // Ensure it's converted to an integer value as required by Paystack
            $amount = (int) round($request->amount * 100);
            
            // Prepare the request data
            $data = [
                'email' => $request->email,
                'amount' => $amount,
                'currency' => $request->currency ?? 'NGN',
                'callback_url' => $request->callback_url,
                'metadata' => $request->metadata ?? [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                ],
                'reference' => 'MMART-PS-' . time() . '-' . $order->id,
            ];
            
            // Initialize the payment with Paystack
            $curl = curl_init();
            
            curl_setopt_array($curl, [
                CURLOPT_URL => 'https://api.paystack.co/transaction/initialize',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $secretKey,
                    'Content-Type: application/json',
                    'Cache-Control: no-cache',
                ],
            ]);
            
            $response = curl_exec($curl);
            $err = curl_error($curl);
            
            curl_close($curl);
            
            if ($err) {
                Log::error('Paystack API error', [
                    'error' => $err
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to initialize payment: ' . $err
                ], 500);
            }
            
            $result = json_decode($response, true);
            
            if (!$result['status']) {
                Log::error('Paystack initialization failed', [
                    'response' => $result
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to initialize payment: ' . ($result['message'] ?? 'Unknown error')
                ], 500);
            }
            
            // Create a payment record
            $payment = new \App\Models\Payment([
                'order_id' => $order->id,
                'payment_method' => 'paystack',
                'amount' => $request->amount,
                'currency' => $request->currency ?? 'NGN',
                'reference' => $result['data']['reference'],
                'status' => 'pending',
                'payment_data' => json_encode($result['data']),
            ]);
            
            $payment->save();
            
            // Update order payment method
            $order->payment_method = 'paystack';
            $order->save();
            
            Log::info('Paystack payment initialized successfully', [
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'reference' => $result['data']['reference']
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Payment initialized successfully',
                'data' => $result['data']
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error initializing Paystack payment', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to initialize payment: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Initialize a Flutterwave payment
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function initializeFlutterwavePayment(Request $request)
    {
        try {
            Log::info('Initializing Flutterwave payment', [
                'request_data' => $request->except(['meta'])
            ]);
            
            // Validate request
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
                'amount' => 'required|numeric',
                'order_id' => 'required',
                'redirect_url' => 'required|url',
                'name' => 'required|string',
                'phone_number' => 'required|string',
                'tx_ref' => 'required|string',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            // Find the order - handle both numeric IDs and order numbers
            $orderId = $request->order_id;
            $order = null;
            
            // Check if it's a numeric ID
            if (is_numeric($orderId)) {
                $order = Order::find($orderId);
            } else {
                // Try to find by order number
                $order = Order::where('order_number', $orderId)->first();
            }
            
            if (!$order) {
                Log::warning('Order not found', [
                    'order_id' => $request->order_id
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Order not found'
                ], 404);
            }
            
            // Get Flutterwave configuration
            $secretKey = env('FLUTTERWAVE_SECRET_KEY');
            
            if (empty($secretKey)) {
                Log::error('Flutterwave API key not configured');
                return response()->json([
                    'status' => 'error',
                    'message' => 'Payment gateway not properly configured'
                ], 500);
            }
            
            // Prepare the request data
            $data = [
                'tx_ref' => $request->tx_ref,
                'amount' => $request->amount,
                'currency' => $request->currency ?? 'NGN',
                'redirect_url' => $request->redirect_url,
                'customer' => [
                    'email' => $request->email,
                    'name' => $request->name,
                    'phonenumber' => $request->phone_number,
                ],
                'meta' => $request->meta ?? [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                ],
                'customizations' => [
                    'title' => 'MMart Order Payment',
                    'description' => 'Payment for order #' . $order->order_number,
                    'logo' => env('APP_URL') . '/images/logo.png',
                ],
            ];
            
            // Initialize the payment with Flutterwave
            $curl = curl_init();
            
            curl_setopt_array($curl, [
                CURLOPT_URL => 'https://api.flutterwave.com/v3/payments',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $secretKey,
                    'Content-Type: application/json',
                ],
            ]);
            
            $response = curl_exec($curl);
            $err = curl_error($curl);
            
            curl_close($curl);
            
            if ($err) {
                Log::error('Flutterwave API error', [
                    'error' => $err
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to initialize payment: ' . $err
                ], 500);
            }
            
            $result = json_decode($response, true);
            
            if ($result['status'] !== 'success') {
                Log::error('Flutterwave initialization failed', [
                    'response' => $result
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to initialize payment: ' . ($result['message'] ?? 'Unknown error')
                ], 500);
            }
            
            // Create a payment record
            $payment = new \App\Models\Payment([
                'order_id' => $order->id,
                'payment_method' => 'flutterwave',
                'amount' => $request->amount,
                'currency' => $request->currency ?? 'NGN',
                'reference' => $request->tx_ref,
                'status' => 'pending',
                'payment_data' => json_encode($result['data']),
            ]);
            
            $payment->save();
            
            // Update order payment method
            $order->payment_method = 'flutterwave';
            $order->save();
            
            Log::info('Flutterwave payment initialized successfully', [
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'reference' => $request->tx_ref
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Payment initialized successfully',
                'data' => $result['data']
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error initializing Flutterwave payment', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to initialize payment: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Update payment method for an existing order
     *
     * @param Request $request
     * @param int $orderId
     * @return \Illuminate\Http\JsonResponse
     */
    public function updatePaymentMethod(Request $request, $orderId)
    {
        try {
            Log::info('Updating payment method for order', [
                'order_id' => $orderId,
                'payment_method' => $request->payment_method
            ]);
            
            // Validate request - only require payment_method
            $validator = Validator::make($request->all(), [
                'payment_method' => 'required|string|in:bank_transfer,paystack,flutterwave,cash_on_delivery',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }
            
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
            
            // Update the payment method
            $order->payment_method = $request->payment_method;
            $order->save();
            
            Log::info('Payment method updated successfully', [
                'order_id' => $orderId,
                'payment_method' => $request->payment_method
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Payment method updated successfully',
                'data' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'payment_method' => $order->payment_method
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating payment method', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update payment method: ' . $e->getMessage()
            ], 500);
        }
    }
    
    public function getPaymentMethods()
    {
        try {
            // Get settings from database
            $settings = \App\Models\Setting::all()->pluck('value', 'key');
            
            // Define available payment methods
            $paymentMethods = [
                [
                    'id' => 'bank_transfer',
                    'name' => 'Manual Bank Transfer',
                    'description' => 'Pay via bank transfer',
                    'icon' => 'bank',
                    'enabled' => isset($settings['payment_bank_transfer']) ? $settings['payment_bank_transfer'] === 'true' : true
                ],
                [
                    'id' => 'paystack',
                    'name' => 'Pay with Paystack',
                    'description' => 'Pay with Paystack',
                    'icon' => 'credit-card',
                    'enabled' => isset($settings['payment_paystack']) ? $settings['payment_paystack'] === 'true' : true
                ],
                [
                    'id' => 'flutterwave',
                    'name' => 'Pay with Flutterwave',
                    'description' => 'Pay with Flutterwave',
                    'icon' => 'credit-card',
                    'enabled' => isset($settings['payment_flutterwave']) ? $settings['payment_flutterwave'] === 'true' : true
                ],
                [
                    'id' => 'cash_on_delivery',
                    'name' => 'Cash on Delivery',
                    'description' => 'Pay when your order is delivered',
                    'icon' => 'money-bill',
                    'enabled' => isset($settings['payment_cash_on_delivery']) ? $settings['payment_cash_on_delivery'] === 'true' : true
                ]
            ];
            
            // Filter out disabled payment methods
            $paymentMethods = array_filter($paymentMethods, function($method) {
                return $method['enabled'];
            });

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
