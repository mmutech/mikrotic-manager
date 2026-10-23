<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 32)->nullable()->after('email');
            $table->string('status', 20)->default('active')->after('password')->index();
            $table->timestamp('last_login_at')->nullable()->after('status');
            $table->timestamp('password_changed_at')->nullable()->after('last_login_at');
            $table->boolean('must_change_password')->default(false)->after('password_changed_at');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropSoftDeletes();
            $table->dropColumn([
                'phone',
                'status',
                'last_login_at',
                'password_changed_at',
                'must_change_password',
            ]);
        });
    }
};
