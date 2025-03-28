<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('title');
            $table->text('description');
            $table->string('image')->nullable();
            $table->string('bg_color')->default('#FFFFFF');
            $table->string('img_bg_color')->default('#FFFFFF');
            $table->string('link')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        
        // Insert some default banners
        DB::table('banners')->insert([
            [
                'label' => 'Monthly Promotion',
                'title' => 'SHOP & SAVE BIG',
                'description' => 'Get up to 30% off on all groceries and household essentials',
                'image' => '/banners/groceries-banner.png',
                'link' => '/promotions',
                'bg_color' => '#0066b2',
                'img_bg_color' => '#e0f2ff',
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'label' => 'New Users',
                'title' => 'WELCOME OFFER',
                'description' => 'First-time shoppers get extra 15% off with code WELCOME15',
                'image' => '/banners/welcome-banner.png',
                'link' => '/welcome',
                'bg_color' => '#e6f7ff',
                'img_bg_color' => '#ffffff',
                'active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('banners');
    }
};
