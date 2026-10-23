<?php

namespace Tests\Feature\Vouchers;

use App\Models\User;
use App\Models\Voucher;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoucherPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_users_with_vouchers_generate_can_generate(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permissions::VOUCHERS_VIEW);
        $this->assertFalse($user->can('generate', Voucher::class));
        $user->givePermissionTo(Permissions::VOUCHERS_GENERATE);
        $this->assertTrue($user->can('generate', Voucher::class));
    }

    public function test_revoke_requires_vouchers_revoke_permission(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo([Permissions::VOUCHERS_VIEW, Permissions::VOUCHERS_PROVISION]);
        $voucher = Voucher::factory()->create();
        $this->assertFalse($user->can('revoke', $voucher));
        $user->givePermissionTo(Permissions::VOUCHERS_REVOKE);
        $this->assertTrue($user->can('revoke', $voucher));
    }
}
