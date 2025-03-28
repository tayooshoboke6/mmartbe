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
        Schema::create('notification_bars', function (Blueprint $table) {
            $table->id();
            $table->string('message');
            $table->string('bg_color')->default('#0071BC');
            $table->string('text_color')->default('#FFFFFF');
            $table->boolean('is_active')->default(true);
            $table->string('link')->nullable();
            $table->string('link_text')->nullable();
            $table->timestamps();
        });
        
        // Insert a default notification bar
        DB::table('notification_bars')->insert([
            'message' => 'Free shipping on all orders above ₦5,000',
            'bg_color' => '#0071BC',
            'text_color' => '#FFFFFF',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_bars');
    }
};
