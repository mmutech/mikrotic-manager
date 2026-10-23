<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('router_id')->nullable()->constrained()->nullOnDelete();
            $table->string('correlation_id', 64)->nullable()->index();
            $table->string('method', 8);
            $table->string('endpoint', 255);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->boolean('success')->default(false)->index();
            $table->string('error_category', 40)->nullable();
            $table->text('error_message')->nullable();
            $table->json('request_meta')->nullable();
            $table->json('response_meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['router_id', 'created_at']);
            $table->index(['created_at', 'success']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_logs');
    }
};
