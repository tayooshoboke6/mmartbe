<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Str;
use App\Models\VerificationCode;

class AuthController extends Controller
{
    /**
     * Register a new user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'phone' => 'required|string|unique:users,phone',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Generate a verification code (6 digits)
        $code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

        // Log the verification code for debugging
        Log::info('Registration verification code generated', [
            'phone' => $request->phone,
            'email' => $request->email,
            'code' => $code
        ]);

        // Store the code in the database for later verification
        VerificationCode::create([
            'phone' => $request->phone,
            'code' => $code,
            'expires_at' => now()->addMinutes(30),
        ]);
        
        // Send the verification code via SMS
        $termiiService = app(\App\Services\TermiiService::class);
        $message = "Your MMart Plus verification code is $code. It expires in 30 minutes.";
        
        $result = $termiiService->sendOtp($request->phone, $message, 6, 3, 30, 'dnd');
        
        // Log the result of sending the verification code
        Log::info('Verification code send result', [
            'success' => $result['success'],
            'phone' => $request->phone,
            'data' => $result['data'] ?? null,
            'message' => $result['message'] ?? null
        ]);
        
        if ($result['success']) {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'phone' => $request->phone,
                'role' => 'customer', // Default role
            ]);

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message' => 'User registered successfully',
                'user' => $user,
                'token' => $token,
            ], 201);
        } else {
            return response()->json([
                'message' => 'Failed to send verification code',
                'error' => $result['message'],
            ], 500);
        }
    }

    /**
     * Login user and create token.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json([
                'message' => 'Invalid login credentials',
            ], 401);
        }

        $user = User::where('email', $request->email)->firstOrFail();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'user' => $user,
            'token' => $token,
        ]);
    }

    /**
     * Logout user (revoke the token).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Successfully logged out',
        ]);
    }

    /**
     * Update user profile.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'zip_code' => 'nullable|string|max:20',
            'profile_photo' => 'nullable|string',
            'current_password' => 'required_with:new_password|string',
            'new_password' => 'sometimes|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Update profile fields
        $user->update($request->only([
            'name', 'phone', 'address', 'city', 'state', 'zip_code', 'profile_photo'
        ]));

        // Handle password update if provided
        if ($request->has('current_password') && $request->has('new_password')) {
            // Verify current password
            if (!Hash::check($request->current_password, $user->password)) {
                return response()->json(['errors' => ['current_password' => ['Current password is incorrect']]], 422);
            }
            
            // Update password
            $user->password = Hash::make($request->new_password);
            $user->save();
        }

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $user,
        ]);
    }

    /**
     * Send password reset link.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $status = Password::sendResetLink(
            $request->only('email')
        );

        return $status === Password::RESET_LINK_SENT
            ? response()->json(['message' => __($status)])
            : response()->json(['message' => __($status)], 400);
    }

    /**
     * Reset password.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password)
                ])->setRememberToken(Str::random(60));

                $user->save();

                event(new PasswordReset($user));
            }
        );

        return $status === Password::PASSWORD_RESET
            ? response()->json(['message' => __($status)])
            : response()->json(['message' => __($status)], 400);
    }

    /**
     * Refresh the user's token.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function refreshToken(Request $request)
    {
        // Get the authenticated user
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated'
            ], 401);
        }
        
        // Revoke all existing tokens
        $user->tokens()->delete();
        
        // Create a new token
        $token = $user->createToken('auth_token')->plainTextToken;
        
        return response()->json([
            'message' => 'Token refreshed successfully',
            'token' => $token,
            'user' => $user
        ]);
    }

    /**
     * Register a user initially without verification.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function registerInitial(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'phone' => 'required|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'phone' => $request->phone,
            'role' => 'customer', // Default role
            'phone_verified' => false, // Phone not verified yet
        ]);

        // Return user ID for verification step
        return response()->json([
            'message' => 'User registered initially. Verification required.',
            'user_id' => $user->id,
        ], 201);
    }

    /**
     * Send verification code to user's phone.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function sendVerificationCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Generate a verification code (6 digits)
        $code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

        // Log the verification code for debugging
        Log::info('Verification code generated', [
            'phone' => $request->phone,
            'code' => $code
        ]);

        // Store the code in the database for later verification
        VerificationCode::create([
            'phone' => $request->phone,
            'code' => $code,
            'expires_at' => now()->addMinutes(30),
        ]);
        
        // Send the verification code via SMS
        $termiiService = app(\App\Services\TermiiService::class);
        $message = "Your MMart Plus verification code is $code. It expires in 30 minutes.";
        
        $result = $termiiService->sendOtp($request->phone, $message, 6, 3, 30, 'dnd');
        
        // Log the result of sending the verification code
        Log::info('Verification code send result', [
            'success' => $result['success'],
            'phone' => $request->phone,
            'data' => $result['data'] ?? null,
            'message' => $result['message'] ?? null
        ]);
        
        if ($result['success']) {
            return response()->json([
                'message' => 'Verification code sent successfully',
                'pin_id' => $result['data']['pinId'] ?? null,
            ]);
        } else {
            return response()->json([
                'message' => 'Failed to send verification code',
                'error' => $result['message'],
            ], 500);
        }
    }

    /**
     * Verify phone number with the provided code.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function verifyPhone(Request $request)
    {
        // Log the incoming request data
        Log::info('Verify phone request received', [
            'request_data' => $request->all()
        ]);
        
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'code' => 'required|string',
            'pin_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            Log::warning('Verify phone validation failed', [
                'errors' => $validator->errors()->toArray()
            ]);
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::find($request->user_id);
        
        if (!$user) {
            Log::warning('User not found for verification', [
                'user_id' => $request->user_id
            ]);
            return response()->json(['message' => 'User not found'], 404);
        }
        
        // Find the verification code in the database
        $formattedPhone = $this->formatPhoneNumber($user->phone);
        
        Log::info('Phone number formats', [
            'original_phone' => $user->phone,
            'formatted_phone' => $formattedPhone
        ]);
        
        $verificationCode = VerificationCode::where(function($query) use ($formattedPhone, $user) {
                $query->where('phone', $formattedPhone)
                      ->orWhere('phone', $user->phone);
            })
            ->where('code', $request->code)
            ->where('used', false)
            ->where('expires_at', '>', now())
            ->latest()
            ->first();
            
        // Log the verification code check
        Log::info('Verification code check from database', [
            'user_phone' => $user->phone,
            'code_found' => $verificationCode ? true : false,
            'submitted_code' => $request->code,
            'query_conditions' => [
                'phone' => $user->phone,
                'code' => $request->code,
                'used' => false,
                'expires_at > now' => true
            ],
            'all_verification_codes' => VerificationCode::where('phone', $user->phone)->get(['id', 'phone', 'code', 'used', 'expires_at'])
        ]);
        
        // Check if a valid verification code was found
        $codeMatches = $verificationCode !== null;
        
        // If pin_id is provided, also verify with Termii
        $termiiVerified = false;
        if ($request->pin_id) {
            $termiiService = app(\App\Services\TermiiService::class);
            $result = $termiiService->verifyOtp($request->pin_id, $request->code);
            $termiiVerified = $result['success'] && isset($result['data']['verified']) && $result['data']['verified'];
        }
        
        // If either verification method succeeds
        if ($codeMatches || $termiiVerified) {
            // Mark the verification code as used
            if ($verificationCode) {
                $verificationCode->used = true;
                $verificationCode->save();
            }
            
            // Update user's phone_verified status
            $user->phone_verified = true;
            $user->save();
            
            // Generate token for the user
            $token = $user->createToken('auth_token')->plainTextToken;
            
            return response()->json([
                'message' => 'Phone verified successfully',
                'user' => $user,
                'token' => $token
            ]);
        } else {
            return response()->json([
                'message' => 'Invalid verification code',
            ], 422);
        }
    }

    /**
     * Update user's phone number and send a new verification code.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function updatePhone(Request $request)
    {
        // Log the incoming request data
        Log::info('Update phone request received', [
            'request_data' => $request->all()
        ]);
        
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'phone' => 'required|string',
        ]);

        if ($validator->fails()) {
            Log::warning('Update phone validation failed', [
                'errors' => $validator->errors()->toArray()
            ]);
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::find($request->user_id);
        
        if (!$user) {
            Log::warning('User not found for phone update', [
                'user_id' => $request->user_id
            ]);
            return response()->json(['message' => 'User not found'], 404);
        }
        
        // Check if the new phone number is already in use by another user
        $existingUser = User::where('phone', $request->phone)
            ->where('id', '!=', $user->id)
            ->first();
            
        if ($existingUser) {
            Log::warning('Phone number already in use', [
                'phone' => $request->phone,
                'existing_user_id' => $existingUser->id
            ]);
            return response()->json([
                'message' => 'This phone number is already registered with another account'
            ], 422);
        }
        
        // Update the user's phone number
        $user->phone = $request->phone;
        $user->phone_verified = false;
        $user->save();
        
        // Generate a new verification code (6 digits)
        $code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
        
        // Log the verification code for debugging
        Log::info('New verification code generated for phone update', [
            'user_id' => $user->id,
            'phone' => $user->phone,
            'code' => $code
        ]);
        
        // Store the code in the database for later verification
        VerificationCode::create([
            'phone' => $user->phone,
            'code' => $code,
            'expires_at' => now()->addMinutes(30),
        ]);
        
        // Send the verification code via SMS using the DirectSmsController
        $directSmsController = new \App\Http\Controllers\Auth\DirectSmsController();
        $smsRequest = new Request([
            'phone' => $user->phone,
            'user_id' => $user->id
        ]);
        
        try {
            $directSmsController->sendVerificationCode($smsRequest);
            
            // Log successful verification code sending
            Log::info('Verification code sent successfully for phone update', [
                'user_id' => $user->id,
                'phone' => $user->phone
            ]);
            
            return response()->json([
                'message' => 'Phone number updated and verification code sent successfully',
                'user' => $user
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send verification code during phone update', [
                'user_id' => $user->id,
                'phone' => $user->phone,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'message' => 'Failed to send verification code to the new number',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

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
