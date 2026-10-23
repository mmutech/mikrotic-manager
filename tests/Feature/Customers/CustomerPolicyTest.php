<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_customers_manage_required_for_create(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permissions::CUSTOMERS_VIEW);
        $this->assertFalse($user->can('create', Customer::class));
        $user->givePermissionTo(Permissions::CUSTOMERS_MANAGE);
        $this->assertTrue($user->can('create', Customer::class));
    }
}
