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
        // Skip creating users table as it already exists
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password')->nullable(); // Nullable for social auth
                $table->string('phone')->nullable();
                $table->string('profile_image')->nullable();
                $table->string('role')->default('customer'); // customer, admin, etc.
                
                // Social authentication fields
                $table->string('google_id')->nullable();
                $table->string('apple_id')->nullable();
                
                $table->rememberToken();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Do nothing in down method to avoid accidentally dropping the users table
    }
};
