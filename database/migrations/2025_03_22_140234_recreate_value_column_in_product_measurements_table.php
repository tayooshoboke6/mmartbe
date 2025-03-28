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
        Schema::table('product_measurements', function (Blueprint $table) {
            // Drop the existing decimal column
            $table->dropColumn('value');
        });

        Schema::table('product_measurements', function (Blueprint $table) {
            // Recreate as string column
            $table->string('value')->after('unit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_measurements', function (Blueprint $table) {
            // Drop the string column
            $table->dropColumn('value');
        });

        Schema::table('product_measurements', function (Blueprint $table) {
            // Recreate as decimal column
            $table->decimal('value', 10, 2)->after('unit');
        });
    }
};
