<?php

namespace Tests\Feature\Transactions;

use App\Models\Transaction;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_transactions_view_only_for_viewing(): void
    {
        $user = User::factory()->create();
        $tx = Transaction::factory()->create();
        $this->assertFalse($user->can('view', $tx));
        $user->givePermissionTo(Permissions::TRANSACTIONS_VIEW);
        $this->assertTrue($user->can('view', $tx));
    }

    public function test_verify_requires_transactions_verify(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permissions::TRANSACTIONS_VIEW);
        $tx = Transaction::factory()->create();
        $this->assertFalse($user->can('verify', $tx));
        $user->givePermissionTo(Permissions::TRANSACTIONS_VERIFY);
        $this->assertTrue($user->can('verify', $tx));
    }
}
