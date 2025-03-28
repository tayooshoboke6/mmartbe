<?php

// This is a test script to test the payment endpoint
// It will:
// 1. Login to get an authentication token
// 2. Make a payment request for a test order
// 3. Open the payment link in the default browser

// Test user credentials
$credentials = [
    'email' => 'test@example.com',
    'password' => 'password123'
];

// Base URL for API
$baseUrl = 'http://localhost:8000/api';

// Test order ID (replace with an actual order ID from your database)
$orderId = 3; // Using an existing order ID

// Step 1: Login to get authentication token
echo "Logging in...\n";
$loginResponse = makeRequest('POST', "$baseUrl/login", $credentials);
echo "Login HTTP Status Code: " . $loginResponse['status_code'] . "\n\n";

// Print the raw response for debugging
echo "Raw Login Response:\n" . $loginResponse['raw_response'] . "\n\n";

// Parse the response
$responseData = json_decode($loginResponse['raw_response'], true);
echo "Parsed Login Response:\n" . json_encode($responseData, JSON_PRETTY_PRINT) . "\n\n";

if ($loginResponse['status_code'] !== 200 || empty($responseData['token'])) {
    echo "Login failed. Cannot proceed with payment test.\n";
    exit(1);
}

$token = $responseData['token'];
echo "Authentication token: " . $token . "\n\n";

// Step 2: Make a payment request
echo "Initiating payment for order #$orderId...\n";
$paymentData = [
    'payment_method' => 'card',
    'currency' => 'NGN',
    'country' => 'NG',
    'email' => 'test@example.com',
    'phone_number' => '08012345678',
    'name' => 'Buck Franecki II'
];

$paymentResponse = makeRequest('POST', "$baseUrl/orders/$orderId/payment", $paymentData, $token);
echo "Payment HTTP Status Code: " . $paymentResponse['status_code'] . "\n\n";

// Print the raw response for debugging
echo "Raw Payment Response:\n" . $paymentResponse['raw_response'] . "\n\n";

// Parse the response
$paymentData = json_decode($paymentResponse['raw_response'], true);
echo "Parsed Payment Response:\n" . json_encode($paymentData, JSON_PRETTY_PRINT) . "\n\n";

if ($paymentResponse['status_code'] !== 200 || empty($paymentData['redirect_url'])) {
    echo "Payment initiation failed.\n";
    exit(1);
}

// Step 3: Open the payment link in the default browser
$paymentLink = $paymentData['redirect_url'];
echo "Opening payment link in browser: $paymentLink\n";

// Open the payment link in the default browser
if (PHP_OS_FAMILY === 'Windows') {
    exec("start $paymentLink");
} elseif (PHP_OS_FAMILY === 'Darwin') { // macOS
    exec("open $paymentLink");
} elseif (PHP_OS_FAMILY === 'Linux') {
    exec("xdg-open $paymentLink");
} else {
    echo "Could not open browser automatically. Please open this URL manually: $paymentLink\n";
}

echo "Test completed successfully!\n";

/**
 * Helper function to make HTTP requests
 *
 * @param string $method HTTP method (GET, POST, etc.)
 * @param string $url URL to make request to
 * @param array $data Data to send with request
 * @param string|null $token Authentication token
 * @return array Response with status code and body
 */
function makeRequest($method, $url, $data = [], $token = null) {
    $ch = curl_init();
    
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    $headers = ['Content-Type: application/json'];
    if ($token) {
        $headers[] = "Authorization: Bearer $token";
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    
    $response = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        echo 'Curl error: ' . curl_error($ch) . "\n";
    }
    
    curl_close($ch);
    
    return [
        'status_code' => $statusCode,
        'raw_response' => $response,
        'body' => json_decode($response, true)
    ];
}
