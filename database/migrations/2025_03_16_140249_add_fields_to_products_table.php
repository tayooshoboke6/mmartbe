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
            // Add short_description field
            $table->text('short_description')->nullable()->after('description');
            
            // Add expiry_date field
            $table->date('expiry_date')->nullable()->after('barcode');
            
            // Add category_id foreign key if it doesn't exist
            if (!Schema::hasColumn('products', 'category_id')) {
                $table->unsignedBigInteger('category_id')->nullable()->after('barcode');
                $table->foreign('category_id')->references('id')->on('categories')->onDelete('set null');
            }
            
            // Add is_new_arrival field
            $table->boolean('is_new_arrival')->default(false)->after('is_featured');
            
            // Add total_sold field
            $table->integer('total_sold')->default(0)->after('stock_quantity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'short_description',
                'expiry_date',
                'is_new_arrival',
                'total_sold'
            ]);
            
            // Only drop if we added it
            if (Schema::hasColumn('products', 'category_id') && !Schema::hasColumn('products', 'category_id_original')) {
                $table->dropForeign(['category_id']);
                $table->dropColumn('category_id');
            }
        });
    }
};
