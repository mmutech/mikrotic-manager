<?php

namespace Tests\Unit;

use App\Enums\ProvisioningStatus;
use App\Enums\VoucherLifecycleStatus;
use App\Models\Plan;
use App\Models\Voucher;
use PHPUnit\Framework\TestCase;

class ConnectedTimeLimitTest extends TestCase
{
    public function test_plan_exposes_the_requested_connected_time_options(): void
    {
        $this->assertSame([
            1800 => '30 minutes',
            3600 => '1 hour',
            7200 => '2 hours',
            14400 => '4 hours',
            28800 => '8 hours',
            86400 => '24 hours',
            0 => 'No maximum time limit',
        ], Plan::connectedTimeOptions());
    }

    public function test_zero_connected_time_has_no_finite_label(): void
    {
        $this->assertNull((new Plan(['time_allowance_seconds' => 0]))->connectedTimeLabel());
        $this->assertSame(
            '1 hour',
            (new Plan(['time_allowance_seconds' => 3600]))->connectedTimeLabel(),
        );
    }

    public function test_voucher_formats_live_session_duration_and_consumption(): void
    {
        $this->assertSame('1h 1m 1s', Voucher::formatDuration(3661));
        $this->assertSame('1.5 KB', Voucher::formatBytes(1536));
        $this->assertSame('0s', Voucher::formatDuration(0));
        $this->assertSame('0.0 B', Voucher::formatBytes(0));
    }

    public function test_zero_connected_time_allowance_is_not_exhausted(): void
    {
        $voucher = new Voucher([
            'lifecycle_status' => VoucherLifecycleStatus::Active,
            'provisioning_status' => ProvisioningStatus::Provisioned,
            'data_allowance_bytes' => null,
            'time_allowance_seconds' => 0,
            'time_used_seconds' => 999,
        ]);

        $this->assertTrue($voucher->canBeProvisioned());
    }
}
