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
        Schema::create('store_addresses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('formatted_address');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('opening_hours')->nullable();
            $table->boolean('is_pickup_location')->default(false);
            $table->boolean('is_delivery_location')->default(false);
            $table->integer('delivery_radius_km')->nullable();
            $table->json('geofence_coordinates')->nullable();
            $table->decimal('delivery_base_fee', 10, 2)->nullable();
            $table->decimal('delivery_fee_per_km', 10, 2)->nullable();
            $table->decimal('free_delivery_threshold', 10, 2)->nullable();
            $table->decimal('minimum_order_value', 10, 2)->nullable();
            $table->timestamps();
        });

        // Insert sample store data
        DB::table('store_addresses')->insert([
            'name' => 'Lekki Store',
            'formatted_address' => '39 Kusenla Rd Ikate-Elegushi, Lekki, Lagos, 106104',
            'phone' => '1234567890',
            'email' => 'store@mmart.com',
            'latitude' => '6.4441339',
            'longitude' => '3.4910538',
            'is_active' => true,
            'is_pickup_location' => true,
            'is_delivery_location' => true,
            'delivery_radius_km' => 10,
            'delivery_base_fee' => 1000.00,
            'delivery_fee_per_km' => 100.00,
            'free_delivery_threshold' => 10000.00,
            'minimum_order_value' => 5000.00,
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('store_addresses');
    }
};
