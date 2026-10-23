<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\Voucher;
use App\Services\Vouchers\VoucherProvisioningService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RevokeVoucherJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    /** @var array<int,int> */
    public array $backoff = [5, 15, 60];
    public int $timeout = 60;

    public function __construct(
        public readonly int $voucherId,
        public readonly ?int $actorId = null,
    ) {}

    public function handle(VoucherProvisioningService $service): void
    {
        $voucher = Voucher::find($this->voucherId);
        if (! $voucher) {
            return;
        }
        $actor = $this->actorId ? User::find($this->actorId) : null;
        if ($actor) {
            auth()->setUser($actor);
        }
        $service->revoke($voucher);
    }

    public function failed(?Throwable $exception): void
    {
    }
}
