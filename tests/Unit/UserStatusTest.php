<?php

namespace Tests\Unit;

use App\Enums\UserStatus;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Services\AuditLogger;
use App\Services\Users\UserStatusService;
use PHPUnit\Framework\TestCase;

class UserStatusTest extends TestCase
{
    public function test_it_exposes_only_active_and_inactive_statuses(): void
    {
        $this->assertSame([
            'active' => 'Active',
            'inactive' => 'Inactive',
        ], UserStatus::options());

        $this->assertTrue(UserStatus::Active->isActive());
        $this->assertFalse(UserStatus::Inactive->isActive());
        $this->assertStringContainsString('emerald', UserStatus::Active->badgeClasses());
        $this->assertStringContainsString('slate', UserStatus::Inactive->badgeClasses());
    }

    public function test_user_reports_inactive_when_not_active(): void
    {
        $this->assertFalse((new User(['status' => UserStatus::Active]))->isInactive());
        $this->assertTrue((new User(['status' => UserStatus::Inactive]))->isInactive());
    }

    public function test_only_super_admins_can_manage_user_status(): void
    {
        $policy = new UserPolicy;

        $superAdmin = $this->createMock(User::class);
        $superAdmin->method('isSuperAdmin')->willReturn(true);

        $networkAdmin = $this->createMock(User::class);
        $networkAdmin->method('isSuperAdmin')->willReturn(false);

        $this->assertTrue($policy->manageStatus($superAdmin));
        $this->assertFalse($policy->manageStatus($networkAdmin));
    }

    public function test_status_service_blocks_self_changes_and_no_op_changes_without_auditing(): void
    {
        $user = (new User)->forceFill([
            'id' => 1,
            'status' => UserStatus::Active,
        ]);

        $audit = $this->createMock(AuditLogger::class);
        $audit->expects($this->never())->method('record');

        $service = new UserStatusService($audit);

        $this->assertFalse($service->change($user, UserStatus::Inactive, $user));
        $this->assertFalse($service->change($user, UserStatus::Active, (new User)->forceFill(['id' => 2])));
    }
}
