<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;

class PaymentMethodsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        try {
            // Remove old payment methods that are no longer used
            Setting::where('key', 'payment_credit_card')->delete();
            Setting::where('key', 'payment_paypal')->delete();
            
            // Add new payment methods if they don't exist
            $newPaymentMethods = [
                [
                    'key' => 'payment_flutterwave',
                    'value' => 'true',
                    'group' => 'payment',
                    'description' => 'Enable Flutterwave card payments'
                ],
                [
                    'key' => 'payment_paystack',
                    'value' => 'true',
                    'group' => 'payment',
                    'description' => 'Enable Paystack card payments'
                ]
            ];
            
            foreach ($newPaymentMethods as $method) {
                Setting::updateOrCreate(
                    ['key' => $method['key']],
                    [
                        'value' => $method['value'],
                        'group' => $method['group'],
                        'description' => $method['description']
                    ]
                );
            }
            
            $this->command->info('Payment methods updated successfully.');
        } catch (\Exception $e) {
            Log::error('Error updating payment methods: ' . $e->getMessage());
            $this->command->error('Failed to update payment methods: ' . $e->getMessage());
        }
    }
}
