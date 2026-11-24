<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->string('hotspot_profile', 64)->nullable();
            $table->unsignedInteger('upload_speed_kbps')->nullable();
            $table->unsignedInteger('download_speed_kbps')->nullable();
        });

        DB::table('vouchers')
            ->orderBy('id')
            ->chunkById(100, function ($vouchers): void {
                foreach ($vouchers as $voucher) {
                    $plan = DB::table('plans')
                        ->where('id', $voucher->plan_id)
                        ->first(['code', 'upload_speed_kbps', 'download_speed_kbps']);

                    if ($plan === null) {
                        continue;
                    }

                    $hotspotProfile = DB::table('router_plan_profiles')
                        ->where('router_id', $voucher->router_id)
                        ->where('plan_id', $voucher->plan_id)
                        ->value('hotspot_profile');

                    if ($hotspotProfile === null) {
                        continue;
                    }

                    // Speed snapshots are intentionally left null for legacy
                    // vouchers: provisioning backfills them from the mapped
                    // RouterOS profile instead of guessing historical values.
                    DB::table('vouchers')
                        ->where('id', $voucher->id)
                        ->update([
                            'hotspot_profile' => $hotspotProfile,
                        ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropColumn([
                'hotspot_profile',
                'upload_speed_kbps',
                'download_speed_kbps',
            ]);
        });
    }
};
