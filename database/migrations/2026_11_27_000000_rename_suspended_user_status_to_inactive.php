<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('status', '!=', 'active')
            ->update(['status' => 'inactive']);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('status', 'inactive')
            ->update(['status' => 'suspended']);
    }
};
