<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('router_plan_profiles', function (Blueprint $table) {
            $table->dropUnique(['router_id', 'hotspot_profile']);
            $table->index(['router_id', 'hotspot_profile']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('router_plan_profiles', function (Blueprint $table) {
            $table->dropIndex(['router_id', 'hotspot_profile']);
            $table->unique(['router_id', 'hotspot_profile']);
        });
    }
};
