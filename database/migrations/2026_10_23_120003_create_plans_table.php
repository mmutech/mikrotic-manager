<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('name', 120);
            $table->string('code', 60)->unique();
            $table->text('description')->nullable();

            $table->decimal('price', 12, 2);
            $table->char('currency', 3)->default('NGN');

            // Integer byte quotas. NULL combined with is_unlimited means no data cap.
            $table->unsignedBigInteger('data_allowance_bytes')->nullable();
            $table->boolean('is_unlimited')->default(false);

            // Calendar validity starts at purchase or first use.
            $table->unsignedInteger('validity_seconds')->nullable();
            $table->string('validity_starts', 20)->default('first_use');

            // Maximum connected time, separate from calendar validity.
            $table->unsignedInteger('time_allowance_seconds')->nullable();

            $table->unsignedInteger('download_speed_kbps')->nullable();
            $table->unsignedInteger('upload_speed_kbps')->nullable();

            $table->string('activation_policy', 20)->default('auto');
            $table->string('expiry_policy', 20)->default('calendar');
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'price']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
