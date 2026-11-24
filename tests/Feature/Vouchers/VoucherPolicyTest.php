<?php

namespace Tests\Feature\Vouchers;

use App\Models\Router;
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
        $router = Router::factory()->create();
        $router->users()->attach($user);
        $voucher = Voucher::factory()->create(['router_id' => $router->getKey()]);
        $this->assertFalse($user->can('revoke', $voucher));
        $user->givePermissionTo(Permissions::VOUCHERS_REVOKE);
        $this->assertTrue($user->can('revoke', $voucher));
    }

    public function test_router_scoped_users_cannot_access_vouchers_on_unassigned_routers(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            Permissions::VOUCHERS_VIEW,
            Permissions::VOUCHERS_PROVISION,
            Permissions::VOUCHERS_PRINT,
            Permissions::VOUCHERS_REVOKE,
        ]);
        $assignedRouter = Router::factory()->create();
        $assignedRouter->users()->attach($user);
        $otherRouter = Router::factory()->create();

        $assignedVoucher = Voucher::factory()->create(['router_id' => $assignedRouter->getKey()]);
        $otherVoucher = Voucher::factory()->create(['router_id' => $otherRouter->getKey()]);

        $this->assertTrue($user->can('viewAny', Voucher::class));
        $this->assertTrue($user->can('view', $assignedVoucher));
        $this->assertTrue($user->can('provision', $assignedVoucher));
        $this->assertTrue($user->can('print', $assignedVoucher));
        $this->assertTrue($user->can('revoke', $assignedVoucher));

        $this->assertFalse($user->can('view', $otherVoucher));
        $this->assertFalse($user->can('provision', $otherVoucher));
        $this->assertFalse($user->can('print', $otherVoucher));
        $this->assertFalse($user->can('revoke', $otherVoucher));
    }

    public function test_users_without_router_assignments_cannot_view_vouchers(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permissions::VOUCHERS_VIEW);
        $voucher = Voucher::factory()->create();

        $this->assertFalse($user->can('viewAny', Voucher::class));
        $this->assertFalse($user->can('view', $voucher));
    }
}
