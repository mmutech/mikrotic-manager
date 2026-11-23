<?php

namespace App\Jobs;

use App\Services\Vouchers\VoucherValiditySyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class SyncVoucherValidityJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function middleware(): array
    {
        return [(new WithoutOverlapping('sync-voucher-validity'))->expireAfter(360)];
    }

    public function handle(VoucherValiditySyncService $service): void
    {
        $service->sync();
    }
}
