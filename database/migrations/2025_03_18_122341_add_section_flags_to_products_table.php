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
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_hot_deal')->default(false);
            $table->boolean('is_best_seller')->default(false);
            $table->boolean('is_expiring_soon')->default(false);
            $table->boolean('is_clearance')->default(false);
            $table->boolean('is_recommended')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'is_hot_deal',
                'is_best_seller',
                'is_expiring_soon',
                'is_clearance',
                'is_recommended'
            ]);
        });
    }
};
