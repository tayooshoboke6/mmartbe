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
        Schema::table('store_addresses', function (Blueprint $table) {
            $table->string('address_line1')->after('name');
            $table->string('address_line2')->nullable()->after('address_line1');
            $table->string('city')->nullable()->after('formatted_address');
            $table->string('state')->nullable()->after('city');
            $table->string('postal_code')->nullable()->after('state');
            $table->string('country')->nullable()->after('postal_code');
            $table->text('notes')->nullable()->after('opening_hours');
            $table->boolean('offers_free_delivery')->default(false)->after('minimum_order_value');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('store_addresses', function (Blueprint $table) {
            $table->dropColumn([
                'address_line1',
                'address_line2',
                'city',
                'state',
                'postal_code',
                'country',
                'notes',
                'offers_free_delivery'
            ]);
        });
    }
};
