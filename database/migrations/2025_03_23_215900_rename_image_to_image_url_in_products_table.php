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
        // First check if the image column exists
        if (Schema::hasColumn('products', 'image')) {
            Schema::table('products', function (Blueprint $table) {
                $table->renameColumn('image', 'image_url');
            });
        } else {
            // If the image column doesn't exist, add image_url column
            Schema::table('products', function (Blueprint $table) {
                $table->string('image_url')->nullable()->after('meta_data');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('image_url', 'image');
        });
    }
};
