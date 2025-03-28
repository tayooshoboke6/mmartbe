<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use GuzzleHttp\Client;
use Google\Exception;

class SocialAuthController extends Controller
{
    /**
     * Handle Google authentication.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function googleAuth(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id_token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            // Log the token for debugging
            Log::info('Google auth attempt with token', [
                'token_length' => strlen($request->id_token),
                'client_id' => config('services.google.client_id')
            ]);

            // Verify the Google ID token
            $client = new \Google_Client();
            $client->setClientId(config('services.google.client_id'));
            
            // Add more debugging
            Log::info('Google client configured', [
                'client_id' => $client->getClientId()
            ]);
            
            try {
                try {
                    $payload = $client->verifyIdToken($request->id_token);
                    
                    Log::info('Token verification attempt', [
                        'token_length' => strlen($request->id_token),
                        'client_id' => $client->getClientId()
                    ]);
                    
                    if (!$payload) {
                        Log::error('Invalid Google token - null payload', [
                            'token_prefix' => substr($request->id_token, 0, 20) . '...',
                            'client_id' => $client->getClientId()
                        ]);
                        return response()->json(['message' => 'Invalid Google token - verification failed'], 401);
                    }
                } catch (Exception $e) {
                    Log::error('Google token verification exception', [
                        'error' => $e->getMessage(),
                        'token_prefix' => substr($request->id_token, 0, 20) . '...',
                        'client_id' => $client->getClientId()
                    ]);
                    return response()->json(['message' => 'Google token verification error: ' . $e->getMessage()], 401);
                }
                
                Log::info('Token verification result', [
                    'payload' => $payload ? 'valid' : 'invalid'
                ]);
                
                if (!$payload) {
                    Log::error('Invalid Google token', [
                        'token' => $request->id_token,
                        'error' => 'Invalid token'
                    ]);
                    return response()->json(['message' => 'Invalid Google token'], 401);
                }
                
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
                } else {
                    // Update existing user
                    $user->update([
                        'google_id' => $googleId,
                        'profile_photo' => $profilePhoto ?? $user->profile_photo,
                    ]);
                    
                    Log::info('Updated existing user from Google auth', [
                        'user_id' => $user->id,
                        'email' => $user->email
                    ]);
                }

                $token = $user->createToken('auth_token')->plainTextToken;

                return response()->json([
                    'message' => 'Google authentication successful',
                    'user' => $user,
                    'token' => $token,
                ]);
            } catch (\Exception $e) {
                Log::error('Google token verification failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                return response()->json(['message' => 'Google token verification failed: ' . $e->getMessage()], 401);
            }
        } catch (\Exception $e) {
            Log::error('Google authentication failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
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
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'name' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            Log::info('Test Google auth attempt', [
                'email' => $request->email,
                'name' => $request->name
            ]);

            // Generate a fake Google ID
            $googleId = 'test_' . Str::random(21);
            
            // Find existing user or create new one
            $user = User::where('email', $request->email)->first();

            if (!$user) {
                // Create new user
                $user = User::create([
                    'name' => $request->name,
                    'email' => $request->email,
                    'google_id' => $googleId,
                    'password' => Hash::make(Str::random(16)),
                    'role' => 'customer',
                ]);
                
                Log::info('Created new user from test Google auth', [
                    'user_id' => $user->id,
                    'email' => $user->email
                ]);
            } else {
                // Update existing user
                $user->update([
                    'google_id' => $googleId,
                ]);
                
                Log::info('Updated existing user from test Google auth', [
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
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
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
            'identity_token' => 'required|string',
            'user' => 'sometimes|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            // Verify the Apple identity token
            $appleUserId = $this->verifyAppleToken($request->identity_token);
            
            if (!$appleUserId) {
                Log::error('Invalid Apple token', [
                    'token' => $request->identity_token,
                    'error' => 'Invalid token'
                ]);
                return response()->json(['message' => 'Invalid Apple token'], 401);
            }

            // Get user info from request (only provided on first login)
            $name = $request->user['name'] ?? null;
            $email = $request->user['email'] ?? null;

            // Find existing user or create new one
            $user = User::where('apple_id', $appleUserId)->first();

            if (!$user && $email) {
                // Check if user exists with this email
                $user = User::where('email', $email)->first();
            }

            if (!$user) {
                // Create new user
                $user = User::create([
                    'name' => $name ?? 'Apple User',
                    'email' => $email ?? $appleUserId . '@privaterelay.appleid.com',
                    'apple_id' => $appleUserId,
                    'password' => Hash::make(Str::random(16)),
                    'role' => 'customer',
                ]);
            } else {
                // Update existing user
                $user->update([
                    'apple_id' => $appleUserId,
                    'name' => $name ?? $user->name,
                ]);
            }

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message' => 'Apple authentication successful',
                'user' => $user,
                'token' => $token,
            ]);
        } catch (\Exception $e) {
            Log::error('Apple authentication failed: ' . $e->getMessage());
            return response()->json(['message' => 'Apple authentication failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Verify Apple identity token.
     * 
     * This implements proper JWT verification with Apple's public key.
     *
     * @param string $identityToken
     * @return string|null
     */
    private function verifyAppleToken(string $identityToken)
    {
        try {
            // Fetch Apple's public keys
            $client = new Client();
            $response = $client->get('https://appleid.apple.com/auth/keys');
            $keys = json_decode($response->getBody(), true);
            
            // Parse the JWT without verification to get the key ID (kid)
            $tokenParts = explode('.', $identityToken);
            if (count($tokenParts) !== 3) {
                return null;
            }
            
            $header = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $tokenParts[0])), true);
            $kid = $header['kid'] ?? null;
            
            if (!$kid) {
                return null;
            }
            
            // Find the matching key
            $jwks = JWK::parseKeySet($keys);
            
            // Verify and decode the token
            $payload = JWT::decode($identityToken, $jwks, ['RS256']);
            
            // Validate token claims
            if ($payload->iss !== 'https://appleid.apple.com' || 
                $payload->aud !== config('services.apple.client_id') ||
                $payload->exp < time()) {
                Log::error('Invalid Apple token claims', [
                    'token' => $identityToken,
                    'error' => 'Invalid claims'
                ]);
                return null;
            }
            
            return $payload->sub;
        } catch (\Exception $e) {
            Log::error('Apple token verification failed: ' . $e->getMessage());
            return null;
        }
    }
}
