<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voucher_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('router_id')->constrained()->restrictOnDelete();
            $table->foreignId('hotspot_session_id')->nullable()->constrained()->nullOnDelete();

            // Hash of router + session + counter version + sampled timestamp, prevents duplicate polling rows.
            $table->char('sample_key', 64)->unique();
            $table->timestamp('sampled_at', 3);
            $table->unsignedBigInteger('rx_bytes')->default(0);
            $table->unsignedBigInteger('tx_bytes')->default(0);
            $table->unsignedBigInteger('total_bytes')->default(0);
            $table->bigInteger('delta_bytes')->default(0);
            $table->string('counter_source', 24)->default('active_session');
            $table->boolean('counter_reset')->default(false);
            $table->string('reconciliation_status', 20)->default('pending')->index();
            $table->json('raw')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['voucher_id', 'sampled_at']);
            $table->index(['router_id', 'sampled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_usage');
    }
};
