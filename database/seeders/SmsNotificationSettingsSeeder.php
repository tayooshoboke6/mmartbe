<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;

class SmsNotificationSettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        try {
            // Add SMS notification settings
            $settings = [
                [
                    'key' => 'sms_notifications',
                    'value' => 'false', // Disabled by default
                    'type' => 'boolean',
                    'group' => 'notifications',
                    'description' => 'Enable or disable SMS notifications'
                ],
                [
                    'key' => 'send_order_confirmation_sms',
                    'value' => 'false', // Disabled by default
                    'type' => 'boolean',
                    'group' => 'notifications',
                    'description' => 'Send SMS notification when an order is confirmed'
                ],
                [
                    'key' => 'send_order_status_update_sms',
                    'value' => 'false', // Disabled by default
                    'type' => 'boolean',
                    'group' => 'notifications',
                    'description' => 'Send SMS notification when an order status changes'
                ],
                [
                    'key' => 'low_stock_sms_alerts',
                    'value' => 'false', // Disabled by default
                    'type' => 'boolean',
                    'group' => 'notifications',
                    'description' => 'Send SMS alerts when product stock is low'
                ],
                [
                    'key' => 'promotional_sms',
                    'value' => 'false', // Disabled by default
                    'type' => 'boolean',
                    'group' => 'notifications',
                    'description' => 'Enable or disable promotional SMS messages'
                ],
                [
                    'key' => 'admin_phone_number',
                    'value' => '',
                    'type' => 'string',
                    'group' => 'notifications',
                    'description' => 'Admin phone number for SMS alerts'
                ],
            ];
            
            foreach ($settings as $setting) {
                Setting::updateOrCreate(
                    ['key' => $setting['key']],
                    [
                        'value' => $setting['value'],
                        'type' => $setting['type'],
                        'group' => $setting['group'],
                        'description' => $setting['description']
                    ]
                );
            }
            
            $this->command->info('SMS notification settings added successfully.');
        } catch (\Exception $e) {
            Log::error('Error adding SMS notification settings: ' . $e->getMessage());
            $this->command->error('Failed to add SMS notification settings: ' . $e->getMessage());
        }
    }
}
