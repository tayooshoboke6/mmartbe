<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Auth\Events\PasswordReset;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

// Payment success and error pages
Route::get('/payment/success', function () {
    return view('payment.success');
})->name('payment.success');

Route::get('/payment/error', function () {
    return view('payment.error');
})->name('payment.error');

// Order success and failed pages
Route::get('/orders/{id}/success', function ($id) {
    return view('orders.success', ['order_id' => $id]);
})->name('orders.success');

Route::get('/orders/failed', function () {
    return view('orders.failed');
})->name('orders.failed');

// Mock payment redirect for testing
Route::get('/payment/mock-redirect', function () {
    $txRef = request('tx_ref');
    $status = request('status', 'successful'); // Default to successful
    
    // Log the mock payment callback
    \Illuminate\Support\Facades\Log::info('Mock Payment Callback', [
        'tx_ref' => $txRef,
        'status' => $status
    ]);
    
    // Redirect to the payment callback endpoint
    return redirect()->route('payment.callback', [
        'status' => $status,
        'tx_ref' => $txRef,
        'transaction_id' => 'MOCK_' . time()
    ]);
});

// Password Reset Routes
Route::get('/reset-password/{token}', function (string $token) {
    return view('auth.reset-password', ['token' => $token]);
})->middleware('guest')->name('password.reset');

Route::get('/forgot-password', function () {
    return view('auth.forgot-password');
})->middleware('guest')->name('password.request');

Route::post('/reset-password', function (Illuminate\Http\Request $request) {
    $request->validate([
        'token' => 'required',
        'email' => 'required|email',
        'password' => 'required|min:8|confirmed',
    ]);

    $status = Password::reset(
        $request->only('email', 'password', 'password_confirmation', 'token'),
        function (User $user, string $password) {
            $user->forceFill([
                'password' => Hash::make($password)
            ])->setRememberToken(Str::random(60));

            $user->save();

            event(new PasswordReset($user));
        }
    );

    return $status === Password::PASSWORD_RESET
                ? redirect()->route('login')->with('status', __($status))
                : back()->withErrors(['email' => [__($status)]]);
})->middleware('guest')->name('password.update');

Route::post('/forgot-password', function (Illuminate\Http\Request $request) {
    // This route is just a placeholder for the named route
    // Actual email sending is handled by the API
    return redirect('/');
})->middleware('guest')->name('password.email');

// Test route for Brevo email integration
Route::get('/test-email', function () {
    try {
        Mail::raw('This is a test email from M-Mart+ using Brevo API.', function ($message) {
            $message->to('test@example.com')
                    ->subject('Test Email from M-Mart+');
        });
        
        return 'Email sent successfully! Check your Brevo dashboard for the email.';
    } catch (\Exception $e) {
        return 'Error sending email: ' . $e->getMessage();
    }
});
