<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('group')->default('general');
            $table->text('description')->nullable();
            $table->timestamps();
        });
        
        // Insert default settings
        $this->seedDefaultSettings();
    }
    
    /**
     * Seed default settings
     */
    private function seedDefaultSettings()
    {
        $settings = [
            // General Settings
            ['key' => 'store_name', 'value' => 'M-Mart+', 'group' => 'general'],
            ['key' => 'store_email', 'value' => 'contact@mmart.com', 'group' => 'general'],
            ['key' => 'store_phone', 'value' => '+234 801 234 5678', 'group' => 'general'],
            ['key' => 'store_address', 'value' => '123 Commerce Street, Lagos, Nigeria', 'group' => 'general'],
            ['key' => 'currency_symbol', 'value' => '₦', 'group' => 'general'],
            ['key' => 'default_language', 'value' => 'English', 'group' => 'general'],
            ['key' => 'time_zone', 'value' => 'Africa/Lagos', 'group' => 'general'],
            
            // Payment Settings
            ['key' => 'payment_credit_card', 'value' => 'true', 'group' => 'payment'],
            ['key' => 'payment_paypal', 'value' => 'true', 'group' => 'payment'],
            ['key' => 'payment_bank_transfer', 'value' => 'true', 'group' => 'payment'],
            ['key' => 'payment_cash_on_delivery', 'value' => 'true', 'group' => 'payment'],
            [
                'key' => 'tax_rate',
                'value' => '7.5',
                'group' => 'payment',
                'description' => 'Default tax rate percentage'
            ],
            [
                'key' => 'bank_account_number',
                'value' => '0438795490',
                'group' => 'payment',
                'description' => 'Bank account number for transfers'
            ],
            [
                'key' => 'bank_name',
                'value' => 'Wema bank',
                'group' => 'payment',
                'description' => 'Bank name for transfers'
            ],
            [
                'key' => 'bank_account_name',
                'value' => 'Omoloade samuel',
                'group' => 'payment',
                'description' => 'Bank account name for transfers'
            ],
            // Appearance Settings
            ['key' => 'primary_color', 'value' => '#0066b2', 'group' => 'appearance'],
            ['key' => 'secondary_color', 'value' => '#ff6600', 'group' => 'appearance'],
            ['key' => 'accent_color', 'value' => '#00cc66', 'group' => 'appearance'],
            ['key' => 'dark_mode', 'value' => 'false', 'group' => 'appearance'],
            ['key' => 'touch_slider_sensitivity', 'value' => '80', 'group' => 'appearance'],
            
            // Notification Settings
            ['key' => 'order_confirmation_emails', 'value' => 'true', 'group' => 'notification'],
            ['key' => 'order_status_update_emails', 'value' => 'true', 'group' => 'notification'],
            ['key' => 'low_stock_alerts', 'value' => 'true', 'group' => 'notification'],
            ['key' => 'newsletter_subscription_notifications', 'value' => 'false', 'group' => 'notification'],
            ['key' => 'marketing_emails', 'value' => 'false', 'group' => 'notification'],
        ];
        
        $table = DB::table('settings');
        foreach ($settings as $setting) {
            $table->insert($setting);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
