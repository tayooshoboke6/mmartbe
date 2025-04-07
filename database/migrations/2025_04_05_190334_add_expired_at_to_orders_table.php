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
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('expired_at')->nullable()->comment('Timestamp when the order was marked as expired');
            $table->timestamp('expiration_notified_at')->nullable()->comment('Timestamp when the customer was notified about order expiration');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['expired_at', 'expiration_notified_at']);
        });
    }
};
