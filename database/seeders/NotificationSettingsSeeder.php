<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;

class NotificationSettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        try {
            // Add notification settings
            $notificationSettings = [
                [
                    'key' => 'order_confirmation_emails',
                    'value' => 'true',
                    'group' => 'notification',
                    'description' => 'Send order confirmation emails to customers'
                ],
                [
                    'key' => 'order_status_update_emails',
                    'value' => 'true',
                    'group' => 'notification',
                    'description' => 'Send order status update emails to customers'
                ],
                [
                    'key' => 'low_stock_alerts',
                    'value' => 'true',
                    'group' => 'notification',
                    'description' => 'Enable low stock alerts for administrators'
                ],
                [
                    'key' => 'newsletter_subscription_notifications',
                    'value' => 'false',
                    'group' => 'notification',
                    'description' => 'Send notifications when users subscribe to the newsletter'
                ],
                [
                    'key' => 'marketing_emails',
                    'value' => 'false',
                    'group' => 'notification',
                    'description' => 'Send marketing emails to customers who have opted in'
                ],
            ];
            
            foreach ($notificationSettings as $setting) {
                Setting::updateOrCreate(
                    ['key' => $setting['key']],
                    [
                        'value' => $setting['value'],
                        'group' => $setting['group'],
                        'description' => $setting['description']
                    ]
                );
            }
            
            $this->command->info('Notification settings added successfully.');
        } catch (\Exception $e) {
            Log::error('Error adding notification settings: ' . $e->getMessage());
            $this->command->error('Failed to add notification settings: ' . $e->getMessage());
        }
    }
}
