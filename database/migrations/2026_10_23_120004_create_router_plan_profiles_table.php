<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('router_plan_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('hotspot_profile', 64);
            $table->timestamp('last_synchronized_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['router_id', 'plan_id']);
            $table->unique(['router_id', 'hotspot_profile']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_plan_profiles');
    }
};
