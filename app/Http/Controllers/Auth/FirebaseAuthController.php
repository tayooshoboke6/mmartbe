<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class FirebaseAuthController extends Controller
{
    /**
     * Handle Google Firebase authentication
     */
    public function handleGoogleAuth(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'user' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            // Verify the Firebase ID token
            $tokenData = $this->verifyFirebaseToken($request->token);
            
            if (!$tokenData) {
                return response()->json(['message' => 'Invalid token'], 401);
            }
            
            // Get user info from token or request data
            $email = $tokenData['email'] ?? $request->user['email'] ?? null;
            $name = $tokenData['name'] ?? $request->user['name'] ?? null;
            $picture = $tokenData['picture'] ?? $request->user['photo'] ?? null;
            
            if (!$email) {
                return response()->json(['message' => 'Email is required'], 422);
            }
            
            // Find or create user
            $user = User::where('email', $email)->first();
            
            if (!$user) {
                // Create new user
                $user = User::create([
                    'name' => $name ?? 'User',
                    'email' => $email,
                    'google_id' => $tokenData['sub'] ?? null,
                    'password' => Hash::make(Str::random(24)),
                    'profile_photo' => $picture,
                    'email_verified_at' => now(),
                ]);
            } else {
                // Update existing user
                $user->google_id = $tokenData['sub'] ?? $user->google_id;
                if ($picture) {
                    $user->profile_photo = $picture;
                }
                $user->save();
            }
            
            // Generate token
            $token = $user->createToken('auth_token')->plainTextToken;
            
            return response()->json([
                'token' => $token,
                'user' => $user,
                'message' => 'User authenticated successfully'
            ]);
            
        } catch (\Exception $e) {
            Log::error('Firebase token verification error: ' . $e->getMessage());
            return response()->json(['message' => 'Authentication failed: ' . $e->getMessage()], 401);
        }
    }
    
    /**
     * Handle Apple Firebase authentication
     */
    public function handleAppleAuth(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'user' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            // Verify the Firebase ID token
            $tokenData = $this->verifyFirebaseToken($request->token);
            
            if (!$tokenData) {
                return response()->json(['message' => 'Invalid token'], 401);
            }
            
            // Get user info from token or request data
            $email = $tokenData['email'] ?? $request->user['email'] ?? null;
            $name = $tokenData['name'] ?? $request->user['name'] ?? null;
            
            if (!$email) {
                return response()->json(['message' => 'Email is required'], 422);
            }
            
            // Find or create user
            $user = User::where('email', $email)->first();
            
            if (!$user) {
                // Create new user
                $user = User::create([
                    'name' => $name ?? 'Apple User',
                    'email' => $email,
                    'apple_id' => $tokenData['sub'] ?? null,
                    'password' => Hash::make(Str::random(24)),
                    'email_verified_at' => now(),
                ]);
            } else {
                // Update existing user
                $user->apple_id = $tokenData['sub'] ?? $user->apple_id;
                $user->save();
            }
            
            // Generate token
            $token = $user->createToken('auth_token')->plainTextToken;
            
            return response()->json([
                'token' => $token,
                'user' => $user,
                'message' => 'User authenticated successfully'
            ]);
            
        } catch (\Exception $e) {
            Log::error('Firebase token verification error: ' . $e->getMessage());
            return response()->json(['message' => 'Authentication failed: ' . $e->getMessage()], 401);
        }
    }
    
    /**
     * Verify Firebase ID token
     * 
     * This method verifies a Firebase ID token using the Firebase Admin SDK or a direct request to the Firebase Auth API
     */
    private function verifyFirebaseToken($token)
    {
        try {
            // For debugging
            Log::info('Attempting to verify Firebase token: ' . substr($token, 0, 20) . '...');
            
            // First try the Firebase Auth API directly
            $firebaseProjectId = 'm-mart-b7cd9';
            $response = Http::get("https://identitytoolkit.googleapis.com/v1/accounts:lookup?key=AIzaSyCj9s_WNAAq2MWP4aEH6dcBgGKnqRCsO-g", [
                'idToken' => $token
            ]);
            
            if ($response->successful()) {
                Log::info('Firebase token verified successfully using identitytoolkit API');
                $userData = $response->json();
                
                // Extract and format user data
                if (isset($userData['users']) && count($userData['users']) > 0) {
                    $user = $userData['users'][0];
                    return [
                        'sub' => $user['localId'] ?? null,
                        'email' => $user['email'] ?? null,
                        'email_verified' => $user['emailVerified'] ?? false,
                        'name' => $user['displayName'] ?? null,
                        'picture' => $user['photoUrl'] ?? null,
                    ];
                }
            }
            
            // Fallback to Google's token info endpoint
            Log::info('Falling back to Google token info endpoint');
            $googleResponse = Http::get('https://www.googleapis.com/oauth2/v3/tokeninfo', [
                'id_token' => $token
            ]);
            
            if ($googleResponse->successful()) {
                Log::info('Token verified using Google token info endpoint');
                return $googleResponse->json();
            }
            
            Log::error('Firebase token verification failed: ' . $response->body());
            Log::error('Google token verification failed: ' . $googleResponse->body());
            return null;
        } catch (\Exception $e) {
            Log::error('Firebase token verification exception: ' . $e->getMessage());
            return null;
        }
    }
}
