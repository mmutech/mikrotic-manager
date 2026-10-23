<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-record authorization for users who are not super administrators.
     * A network administrator only sees and operates routers assigned here.
     */
    public function up(): void
    {
        Schema::create('router_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['router_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_user');
    }
};
