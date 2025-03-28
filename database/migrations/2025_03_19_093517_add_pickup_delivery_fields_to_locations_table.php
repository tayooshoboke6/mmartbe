<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            // Basic availability fields
            $table->boolean('is_pickup_available')->default(false);
            $table->boolean('is_delivery_available')->default(false);
            
            // Delivery configuration fields
            $table->float('delivery_radius_km')->nullable();
            $table->json('delivery_zone_polygon')->nullable();
            $table->float('delivery_base_fee')->nullable();
            $table->float('delivery_fee_per_km')->nullable();
            $table->float('delivery_free_threshold')->nullable();
            $table->float('delivery_min_order')->nullable();
            
            // Additional delivery settings
            $table->float('max_delivery_distance_km')->nullable();
            $table->float('outside_geofence_fee')->nullable();
            $table->json('order_value_adjustments')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            // Basic availability fields
            $table->dropColumn('is_pickup_available');
            $table->dropColumn('is_delivery_available');
            
            // Delivery configuration fields
            $table->dropColumn('delivery_radius_km');
            $table->dropColumn('delivery_zone_polygon');
            $table->dropColumn('delivery_base_fee');
            $table->dropColumn('delivery_fee_per_km');
            $table->dropColumn('delivery_free_threshold');
            $table->dropColumn('delivery_min_order');
            
            // Additional delivery settings
            $table->dropColumn('max_delivery_distance_km');
            $table->dropColumn('outside_geofence_fee');
            $table->dropColumn('order_value_adjustments');
        });
    }
};
