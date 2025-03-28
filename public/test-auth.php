<?php
/**
 * Test script for authentication
 * 
 * This script checks if the authentication token is valid
 */

// Configuration
$baseUrl = 'http://127.0.0.1:8000';
$token = '12|w99lx5zdfPLEy0MG8h4RwzDHQbF214MY8fDaz7uF9f9121c9'; // Replace with a valid token

// Initialize cURL session
$ch = curl_init();

// Set cURL options
curl_setopt($ch, CURLOPT_URL, "{$baseUrl}/api/user");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'Authorization: Bearer ' . $token
]);

// Execute cURL session and get the response
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

// Check for cURL errors
if (curl_errno($ch)) {
    echo "cURL Error: " . curl_error($ch) . "\n";
    exit;
}

// Close cURL session
curl_close($ch);

// Output the response
echo "HTTP Status Code: " . $httpCode . "\n\n";
echo "Response:\n";
echo json_encode(json_decode($response), JSON_PRETTY_PRINT);
