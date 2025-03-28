<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use GuzzleHttp\Client;
use App\Models\Order;
use App\Models\Payment;

class DebugController extends Controller
{
    /**
     * Get environment variables status (not values) for debugging
     *
     * @return \Illuminate\Http\Response
     */
    public function getEnvVariables()
    {
        // Only check if variables are set, don't return actual values for security
        $variables = [
            'FLUTTERWAVE_PUBLIC_KEY' => !empty(env('FLUTTERWAVE_PUBLIC_KEY')),
            'FLUTTERWAVE_SECRET_KEY' => !empty(env('FLUTTERWAVE_SECRET_KEY')),
            'FLUTTERWAVE_ENCRYPTION_KEY' => !empty(env('FLUTTERWAVE_ENCRYPTION_KEY')),
            'FLUTTERWAVE_CALLBACK_URL' => !empty(env('FLUTTERWAVE_CALLBACK_URL')),
            'PAYSTACK_PUBLIC_KEY' => !empty(env('PAYSTACK_PUBLIC_KEY')),
            'PAYSTACK_SECRET_KEY' => !empty(env('PAYSTACK_SECRET_KEY')),
            'PAYSTACK_CALLBACK_URL' => !empty(env('PAYSTACK_CALLBACK_URL')),
            'APP_ENV' => env('APP_ENV'),
            'APP_DEBUG' => env('APP_DEBUG'),
            'DB_CONNECTION' => !empty(env('DB_CONNECTION')),
        ];

        return response()->json($variables);
    }

    /**
     * Test direct API call to Flutterwave
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function testFlutterwaveAPI(Request $request)
    {
        try {
            // Get Flutterwave configuration
            $publicKey = env('FLUTTERWAVE_PUBLIC_KEY');
            $secretKey = env('FLUTTERWAVE_SECRET_KEY');
            $encryptionKey = env('FLUTTERWAVE_ENCRYPTION_KEY');

            // Check if API keys are configured
            if (empty($secretKey) || empty($publicKey)) {
                Log::error('Flutterwave API keys not configured', [
                    'public_key_exists' => !empty($publicKey),
                    'secret_key_exists' => !empty($secretKey)
                ]);
                
                return response()->json([
                    'status' => 'error',
                    'message' => 'Flutterwave API keys not configured',
                    'details' => [
                        'public_key_exists' => !empty($publicKey),
                        'secret_key_exists' => !empty($secretKey),
                        'encryption_key_exists' => !empty($encryptionKey)
                    ]
                ], 500);
            }

            // Generate a unique transaction reference
            $tx_ref = 'MMART-DEBUG-' . time();

            // Format payment data for Flutterwave
            $paymentData = [
                'tx_ref' => $tx_ref,
                'amount' => $request->input('amount', 1000),
                'currency' => $request->input('currency', 'NGN'),
                'redirect_url' => $request->input('redirect_url', env('FLUTTERWAVE_CALLBACK_URL', url('/payment/callback'))),
                'customer' => [
                    'email' => $request->input('email', 'test@example.com'),
                    'phone_number' => $request->input('phone', '08012345678'),
                    'name' => $request->input('name', 'Test User')
                ],
                'customizations' => [
                    'title' => 'M-Mart+ Debug Payment',
                    'description' => 'Debug Payment Test',
                    'logo' => url('/logo.png')
                ],
                'meta' => $request->input('meta', [])
            ];

            // Log the request data
            Log::info('Debug: Testing Flutterwave API', [
                'payment_data' => $paymentData,
                'secret_key_length' => strlen($secretKey),
                'secret_key_first_chars' => substr($secretKey, 0, 4) . '...',
                'api_url' => 'https://api.flutterwave.com/v3/payments'
            ]);

            // Make direct API call to Flutterwave
            $client = new Client();
            $response = $client->request('POST', 'https://api.flutterwave.com/v3/payments', [
                'headers' => [
                    'Authorization' => 'Bearer ' . trim($secretKey),
                    'Content-Type' => 'application/json',
                ],
                'json' => $paymentData,
                'http_errors' => false, // Don't throw exceptions for HTTP errors
            ]);

            // Get response details
            $statusCode = $response->getStatusCode();
            $responseBody = $response->getBody()->getContents();
            $responseData = json_decode($responseBody, true);

            // Log the response
            Log::info('Debug: Flutterwave API response', [
                'status_code' => $statusCode,
                'response_status' => $responseData['status'] ?? 'unknown',
                'response_message' => $responseData['message'] ?? 'No message',
                'response_data' => $responseData
            ]);

            // Check if the request was successful
            if ($statusCode >= 200 && $statusCode < 300 && isset($responseData['status']) && $responseData['status'] === 'success') {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Flutterwave API test successful',
                    'data' => $responseData,
                    'request_data' => [
                        'url' => 'https://api.flutterwave.com/v3/payments',
                        'payment_data' => $paymentData,
                        'headers' => [
                            'Authorization' => 'Bearer ' . substr($secretKey, 0, 4) . '...' . substr($secretKey, -4),
                            'Content-Type' => 'application/json',
                        ]
                    ]
                ]);
            } else {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Flutterwave API test failed',
                    'error_details' => [
                        'status_code' => $statusCode,
                        'response_status' => $responseData['status'] ?? 'unknown',
                        'response_message' => $responseData['message'] ?? 'No message',
                    ],
                    'response_data' => $responseData,
                    'request_data' => [
                        'url' => 'https://api.flutterwave.com/v3/payments',
                        'payment_data' => $paymentData,
                        'headers' => [
                            'Authorization' => 'Bearer ' . substr($secretKey, 0, 4) . '...' . substr($secretKey, -4),
                            'Content-Type' => 'application/json',
                        ]
                    ]
                ], $statusCode >= 400 ? $statusCode : 500);
            }
        } catch (\Exception $e) {
            Log::error('Debug: Exception during Flutterwave API test', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Exception during Flutterwave API test: ' . $e->getMessage(),
                'exception' => [
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ], 500);
        }
    }

    /**
     * Get detailed payment information for debugging
     *
     * @param  int  $orderId
     * @return \Illuminate\Http\Response
     */
    public function getPaymentDetails($orderId)
    {
        try {
            $order = Order::findOrFail($orderId);
            $payment = Payment::where('order_id', $orderId)->first();

            return response()->json([
                'status' => 'success',
                'order' => $order,
                'payment' => $payment,
                'payment_gateway_info' => [
                    'flutterwave' => [
                        'public_key_exists' => !empty(env('FLUTTERWAVE_PUBLIC_KEY')),
                        'secret_key_exists' => !empty(env('FLUTTERWAVE_SECRET_KEY')),
                        'encryption_key_exists' => !empty(env('FLUTTERWAVE_ENCRYPTION_KEY')),
                    ],
                    'paystack' => [
                        'public_key_exists' => !empty(env('PAYSTACK_PUBLIC_KEY')),
                        'secret_key_exists' => !empty(env('PAYSTACK_SECRET_KEY')),
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get payment details: ' . $e->getMessage()
            ], 500);
        }
    }
}
