<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\VerificationCode;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class DirectSmsController extends Controller
{
    /**
     * Send a verification code via direct SMS implementation.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendVerificationCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Format the phone number
        $phone = $this->formatPhoneNumber($request->phone);

        // Generate a random 6-digit code
        $code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

        // Store the code in the database for later verification
        VerificationCode::where('phone', $phone)
            ->where('used', false)
            ->update(['used' => true]); // Mark any existing codes as used

        // Create a new verification code
        VerificationCode::create([
            'phone' => $phone,
            'code' => $code,
            'user_id' => $request->user_id ?? null,
            'expires_at' => Carbon::now()->addMinutes(30),
            'used' => false,
        ]);

        // Log the code storage
        Log::info('Verification code stored in database', [
            'phone' => $phone,
            'code' => $code,
        ]);

        // Send the verification code via direct SMS implementation
        $result = $this->sendSms($phone, $code);

        if ($result['success']) {
            return response()->json([
                'message' => 'Verification code sent successfully',
                'data' => [
                    'phone' => $phone,
                ]
            ]);
        } else {
            return response()->json([
                'message' => 'Failed to send verification code',
                'error' => $result['error']
            ], 500);
        }
    }

    /**
     * Send an SMS using direct cURL implementation.
     *
     * @param  string  $phone
     * @param  string  $code
     * @return array
     */
    private function sendSms($phone, $code)
    {
        $apiKey = env('TERMII_API_KEY');
        $senderId = env('TERMII_SENDER_ID', 'N-Alert');
        $message = "Your MMart Plus verification code is {$code}. It expires in 30 minutes.";

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

        Log::info('SMS API Response', [
            'phone' => $phone,
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
    private function formatPhoneNumber($phone)
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
}
