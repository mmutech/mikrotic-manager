<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('router_health_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('identity', 120)->nullable();
            $table->string('ros_version', 60)->nullable();
            $table->string('error_category', 40)->nullable();
            $table->json('details')->nullable();
            $table->timestamp('checked_at')->useCurrent();

            $table->index(['router_id', 'checked_at']);
            $table->index(['status', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_health_checks');
    }
};
