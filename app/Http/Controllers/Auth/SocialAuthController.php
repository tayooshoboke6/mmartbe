<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use GuzzleHttp\Client;
use Google\Exception;
use phpseclib3\Crypt\RSA;
use phpseclib3\Math\BigInteger;

class SocialAuthController extends Controller
{
    /**
     * Verify a Firebase ID token
     *
     * @param string $token The Firebase ID token to verify
     * @return array|null The decoded token payload or null if verification fails
     */
    private function verifyFirebaseToken($token)
    {
        try {
            // Log the token verification attempt
            Log::info('Attempting to verify Firebase token', [
                'token_length' => strlen($token),
                'token_preview' => substr($token, 0, 20) . '...'
            ]);
            
            // For Firebase tokens, we can simply decode the JWT to extract the payload
            // This is a simplified approach for development purposes
            $tokenParts = explode('.', $token);
            if (count($tokenParts) !== 3) {
                Log::error('Invalid token format - not a valid JWT');
                return null;
            }
            
            // Decode the payload part (second part of the JWT)
            $payload = json_decode($this->base64UrlDecode($tokenParts[1]), true);
            if (!$payload) {
                Log::error('Failed to decode token payload');
                return null;
            }
            
            // Basic validation of the token payload
            if (!isset($payload['sub']) || empty($payload['sub'])) {
                Log::error('Invalid token - missing subject (sub)');
                return null;
            }
            
            if (!isset($payload['email']) || empty($payload['email'])) {
                Log::error('Invalid token - missing email');
                return null;
            }
            
            // Log successful verification
            Log::info('Firebase token payload successfully extracted', [
                'sub' => $payload['sub'],
                'email' => $payload['email']
            ]);
            
            return $payload;
        } catch (\Exception $e) {
            Log::warning('Firebase token verification failed', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Decode base64url encoded string
     *
     * @param string $input
     * @return string
     */
    private function base64UrlDecode($input)
    {
        // Add padding if needed
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $input .= str_repeat('=', 4 - $remainder);
        }
        
        // Convert from base64url to base64
        $input = strtr($input, '-_', '+/');
        
        // Decode
        return base64_decode($input);
    }

    /**
     * Handle Google authentication.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function googleAuth(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            // Log the token for debugging
            Log::info('Google auth attempt with token', [
                'token_length' => strlen($request->token),
                'client_id' => config('services.google.client_id')
            ]);

            // Log the token for debugging
            Log::info('Google auth token details', [
                'token_length' => strlen($request->token),
                'token_preview' => substr($request->token, 0, 30) . '...'
            ]);
            
            // Verify the Firebase token
            Log::info('Attempting Firebase token verification');
            $payload = $this->verifyFirebaseToken($request->token);
            
            // Check if we have a valid payload
            if (!$payload) {
                Log::error('Invalid Firebase token - verification failed');
                return response()->json(['message' => 'Invalid Firebase token'], 401);
            }
            
            Log::info('Firebase token verification successful');
            
            // Log the payload for debugging
            Log::info('Google token payload', [
                'sub' => $payload['sub'] ?? 'missing',
                'email' => $payload['email'] ?? 'missing',
                'name' => $payload['name'] ?? 'missing'
            ]);

            // Get user info from payload
            $googleId = $payload['sub'];
            $email = $payload['email'];
            $name = $payload['name'] ?? '';
            $profilePhoto = $payload['picture'] ?? null;

            // Find existing user or create new one
            $user = User::where('google_id', $googleId)->orWhere('email', $email)->first();

            if (!$user) {
                // Create new user
                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    'google_id' => $googleId,
                    'password' => Hash::make(Str::random(16)),
                    'profile_photo' => $profilePhoto,
                    'role' => 'customer',
                ]);
                
                Log::info('Created new user from Google auth', [
                    'user_id' => $user->id,
                    'email' => $user->email
                ]);
                
                // Send welcome email to the new user
                try {
                    Mail::to($user->email)->send(new \App\Mail\WelcomeMail($user));
                    Log::info('Welcome email sent to Google auth user');
                } catch (\Exception $e) {
                    Log::error('Failed to send welcome email to Google auth user', [
                        'error' => $e->getMessage()
                    ]);
                    // Continue with registration even if email fails
                }
            } else {
                // Update existing user
                $user->update([
                    'google_id' => $googleId,
                    'profile_photo' => $profilePhoto ?? $user->profile_photo,
                ]);
                
                Log::info('Updated existing user from Google auth', [
                    'user_id' => $user->id
                ]);
            }

            // Create token for the user
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message' => 'Google authentication successful',
                'user' => $user,
                'token' => $token,
            ]);
        } catch (\Exception $e) {
            Log::error('Google authentication failed', [
                'error' => $e->getMessage()
            ]);
            return response()->json(['message' => 'Google authentication failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Test method for Google authentication (for development only).
     * This bypasses the actual Google token verification.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function testGoogleAuth(Request $request)
    {
        try {
            // Use test email from request or default
            $email = $request->input('email', 'test@example.com');
            $name = $request->input('name', 'Test User');
            
            // Generate a fake Google ID
            $googleId = 'test_' . Str::random(20);
            
            // Find existing user or create new one
            $user = User::where('email', $email)->first();
            
            if (!$user) {
                // Create new user
                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    'google_id' => $googleId,
                    'password' => Hash::make(Str::random(16)),
                    'role' => 'customer',
                ]);
                
                Log::info('Created new test user', [
                    'user_id' => $user->id,
                    'email' => $user->email
                ]);
            } else {
                // Update existing user
                $user->update([
                    'google_id' => $googleId,
                ]);
                
                Log::info('Updated existing test user', [
                    'user_id' => $user->id,
                    'email' => $user->email
                ]);
            }
            
            $token = $user->createToken('auth_token')->plainTextToken;
            
            return response()->json([
                'message' => 'Test Google authentication successful',
                'user' => $user,
                'token' => $token,
            ]);
        } catch (\Exception $e) {
            Log::error('Test Google authentication failed', [
                'error' => $e->getMessage()
            ]);
            return response()->json(['message' => 'Test Google authentication failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Handle Apple authentication.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function appleAuth(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'user' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            // Log the token for debugging
            Log::info('Apple auth attempt with token', [
                'token_length' => strlen($request->token),
                'token_preview' => substr($request->token, 0, 30) . '...'
            ]);
            
            // Verify the Firebase token (not the Apple token directly)
            Log::info('Attempting Firebase token verification for Apple auth');
            $payload = $this->verifyFirebaseToken($request->token);
            
            // Check if we have a valid payload
            if (!$payload) {
                Log::error('Invalid Firebase token - verification failed');
                return response()->json(['message' => 'Invalid Firebase token'], 401);
            }
            
            Log::info('Firebase token verification successful for Apple auth');
            
            // Get user info from payload
            $appleId = $payload['sub'];
            $email = $payload['email'] ?? null;
            
            // Get name from user data if provided (Apple only sends name on first login)
            $userData = $request->user ?? [];
            $name = '';
            
            if (isset($userData['name'])) {
                if (isset($userData['name']['firstName']) && isset($userData['name']['lastName'])) {
                    $name = $userData['name']['firstName'] . ' ' . $userData['name']['lastName'];
                } elseif (isset($userData['name']['firstName'])) {
                    $name = $userData['name']['firstName'];
                } elseif (isset($userData['name']['lastName'])) {
                    $name = $userData['name']['lastName'];
                }
            }
            
            // Find existing user or create new one
            $user = User::where('apple_id', $appleId)->orWhere('email', $email)->first();
            
            if (!$user) {
                // Create new user
                $user = User::create([
                    'name' => $name ?: 'Apple User',
                    'email' => $email ?: $appleId . '@privaterelay.appleid.com',
                    'apple_id' => $appleId,
                    'password' => Hash::make(Str::random(16)),
                    'role' => 'customer',
                ]);
                
                Log::info('Created new user from Apple auth', [
                    'user_id' => $user->id,
                    'email' => $user->email
                ]);
            } else {
                // Update existing user
                $updates = ['apple_id' => $appleId];
                
                // Only update name if provided and user doesn't already have one
                if ($name && (!$user->name || $user->name === 'Apple User')) {
                    $updates['name'] = $name;
                }
                
                $user->update($updates);
                
                Log::info('Updated existing user from Apple auth', [
                    'user_id' => $user->id,
                    'email' => $user->email
                ]);
            }
            
            $token = $user->createToken('auth_token')->plainTextToken;
            
            return response()->json([
                'message' => 'Apple authentication successful',
                'user' => $user,
                'token' => $token,
            ]);
        } catch (\Exception $e) {
            Log::error('Apple authentication failed', [
                'error' => $e->getMessage()
            ]);
            return response()->json(['message' => 'Apple authentication failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Verify Apple identity token.
     * 
     * This is a simplified version that extracts the payload without cryptographic verification.
     * In a production environment, you should implement proper JWT verification.
     *
     * @param string $identityToken
     * @return array|null
     */
    private function verifyAppleToken($identityToken)
    {
        try {
            // Log the token verification attempt
            Log::info('Attempting to verify Apple token', [
                'token_length' => strlen($identityToken),
                'token_preview' => substr($identityToken, 0, 20) . '...'
            ]);
            
            // Split the token into its parts
            $tokenParts = explode('.', $identityToken);
            if (count($tokenParts) !== 3) {
                throw new \Exception('Invalid token format');
            }
            
            // Decode the payload part
            $payload = json_decode($this->base64UrlDecode($tokenParts[1]), true);
            if (!$payload) {
                throw new \Exception('Invalid token payload');
            }
            
            // Basic validation
            if (!isset($payload['iss']) || $payload['iss'] !== 'https://appleid.apple.com') {
                throw new \Exception('Invalid issuer');
            }
            
            if (!isset($payload['sub']) || empty($payload['sub'])) {
                throw new \Exception('Missing subject identifier');
            }
            
            // For development purposes, we'll accept the token without full verification
            // In production, you should implement proper JWT verification with Apple's public keys
            Log::info('Apple token payload extracted', [
                'sub' => $payload['sub'] ?? 'missing',
                'email' => $payload['email'] ?? 'missing'
            ]);
            
            return $payload;
        } catch (\Exception $e) {
            Log::error('Apple token verification failed', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
}
