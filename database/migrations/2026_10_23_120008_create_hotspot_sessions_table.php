<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotspot_sessions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('router_id')->constrained()->restrictOnDelete();
            $table->foreignId('voucher_id')->nullable()->constrained()->nullOnDelete();
            $table->string('username', 64);
            $table->string('session_key', 64)->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('mac_address', 17)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('status', 16)->default('active')->index();

            $table->unsignedBigInteger('rx_bytes')->default(0);
            $table->unsignedBigInteger('tx_bytes')->default(0);
            // Last raw counters used to calculate deltas without double counting.
            $table->unsignedBigInteger('last_rx_bytes')->default(0);
            $table->unsignedBigInteger('last_tx_bytes')->default(0);

            $table->string('termination_reason', 40)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['router_id', 'session_key']);
            $table->index(['router_id', 'status']);
            $table->index(['voucher_id', 'started_at']);
            $table->index(['router_id', 'username']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotspot_sessions');
    }
};
