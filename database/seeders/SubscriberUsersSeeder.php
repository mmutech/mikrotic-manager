<?php

namespace Database\Seeders;

use App\Enums\SubscriptionType;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\SubscriptionPricingService;
use App\Support\Roles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Role;

class SubscriberUsersSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        $this->seedUser(
            role: Roles::NETWORK_ADMIN,
            name: (string) config('mmutech.seed.network_admin_name', 'Network Admin'),
            email: (string) config('mmutech.seed.network_admin_email', 'network@mmutech.com'),
            password: (string) config('mmutech.seed.network_admin_password', 'password'),
        );

        $this->seedUser(
            role: Roles::SALES_AGENT,
            name: (string) config('mmutech.seed.sales_agent_name', 'Sales Agent Demo'),
            email: (string) config('mmutech.seed.sales_agent_email', 'sales@mmutech.com'),
            password: (string) config('mmutech.seed.sales_agent_password', 'password'),
        );
    }

    private function seedUser(string $role, string $name, string $email, string $password): void
    {
        if ($email === '') {
            throw new RuntimeException("Set an email for the {$role} seed user.");
        }

        Role::findOrCreate($role, 'web');

        $user = User::withTrashed()->firstOrNew(['email' => $email]);
        $isNew = ! $user->exists;

        $user->name = $name !== ''
            ? $name
            : str($role)->replace('-', ' ')->title()->toString();
        $user->status = UserStatus::Active;

        if ($isNew || blank($user->password)) {
            if (strlen($password) < 8) {
                throw new RuntimeException(
                    "Set a password of at least 8 characters for the {$role} seed user."
                );
            }

            $user->password = Hash::make($password);
            $user->password_changed_at = now();
        }

        if ($user->trashed()) {
            $user->deleted_at = null;
        }

        $user->save();
        $user->syncRoles([$role]);

        if ($user->activeSubscription() === null) {
            $type = SubscriptionType::tryFrom((string) config('mmutech.seed.subscriber_subscription_type'))
                ?? SubscriptionType::Monthly;

            /** @var SubscriptionPricingService $pricing */
            $pricing = app(SubscriptionPricingService::class);

            $user->subscriptions()->create([
                'plan' => $type->value,
                'type' => $type,
                'status' => 'active',
                'starts_at' => now(),
                'expires_at' => now()->addDays($type->days()),
                'amount' => $pricing->priceFor($type),
                'currency' => $pricing->currency(),
                'metadata' => [
                    'seeded_by' => self::class,
                    'role' => $role,
                ],
            ]);
        }
    }
}
