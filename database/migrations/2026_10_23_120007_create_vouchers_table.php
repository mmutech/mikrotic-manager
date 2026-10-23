<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('router_id')->constrained()->restrictOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('username', 64);
            // Ciphertext produced by Laravel's encrypted cast. Never logged or listed.
            $table->text('password');

            // Snapshot of plan rules at issuance so later plan edits cannot alter a sold voucher.
            $table->unsignedBigInteger('data_allowance_bytes')->nullable();
            $table->unsignedInteger('time_allowance_seconds')->nullable();
            $table->unsignedInteger('validity_seconds')->nullable();
            $table->string('validity_starts', 20)->default('first_use');
            $table->string('expiry_policy', 20)->default('calendar');

            $table->string('lifecycle_status', 20)->default('pending')->index();
            $table->string('provisioning_status', 24)->default('pending')->index();

            $table->timestamp('activated_at')->nullable();
            $table->timestamp('first_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedBigInteger('used_bytes')->default(0);
            $table->unsignedInteger('time_used_seconds')->default(0);
            $table->string('router_user_id', 32)->nullable();
            $table->text('provisioning_error')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['router_id', 'username']);
            $table->index(['router_id', 'lifecycle_status']);
            $table->index(['customer_id', 'lifecycle_status']);
            $table->index(['plan_id', 'created_at']);
            $table->index(['provisioning_status', 'last_synced_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
