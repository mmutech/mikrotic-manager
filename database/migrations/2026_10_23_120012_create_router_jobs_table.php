<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('router_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->string('type', 60)->index();
            $table->string('status', 20)->default('queued')->index();
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->json('payload')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('error_category', 40)->nullable();
            $table->text('error_message')->nullable();
            $table->nullableMorphs('related');
            $table->string('correlation_id', 64)->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['router_id', 'status']);
            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_jobs');
    }
};
