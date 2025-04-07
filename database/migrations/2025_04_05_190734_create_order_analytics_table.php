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
        Schema::create('order_analytics', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique()->index();
            $table->integer('expired_orders_count')->default(0);
            $table->decimal('expired_orders_value', 10, 2)->default(0);
            $table->integer('total_orders_count')->default(0);
            $table->decimal('total_orders_value', 10, 2)->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_analytics');
    }
};
