<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20)->default('paystack');
            $table->string('event_key', 128);
            $table->string('event_type', 80);
            $table->string('reference', 64)->nullable()->index();
            $table->char('payload_hash', 64);
            $table->json('payload');
            $table->string('status', 20)->default('received')->index();
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};
