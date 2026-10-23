<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('reference', 64)->unique();
            $table->string('provider', 20)->default('paystack');
            $table->string('provider_reference', 128)->nullable()->unique();

            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('voucher_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('router_id')->nullable()->constrained()->nullOnDelete();

            $table->decimal('amount', 12, 2);
            $table->char('currency', 3);
            $table->string('status', 20)->default('pending')->index();
            $table->string('channel', 40)->nullable();
            $table->string('gateway_response', 255)->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
