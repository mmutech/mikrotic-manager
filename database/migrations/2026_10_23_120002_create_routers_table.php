<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routers', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('name', 120);
            $table->string('location', 160)->nullable();
            $table->string('site', 80)->nullable();

            // Connection details. The password is stored as an encrypted payload.
            $table->string('host', 253);
            $table->unsignedSmallInteger('rest_port')->default(443);
            $table->string('api_username', 120);
            $table->text('api_password');
            $table->boolean('tls_verify')->default(true);
            $table->timestamp('api_password_rotated_at')->nullable();

            // Last known router facts, populated by read-only probes.
            $table->string('identity', 120)->nullable();
            $table->string('ros_version', 60)->nullable();
            $table->string('board_name', 120)->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->boolean('monitoring_enabled')->default(true);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_error_category', 40)->nullable()->index();
            $table->text('last_error_message')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->unsignedInteger('latency_ms')->nullable();

            $table->json('metadata')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['host', 'rest_port']);
            $table->index(['status', 'monitoring_enabled']);
            $table->index(['monitoring_enabled', 'last_checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routers');
    }
};
