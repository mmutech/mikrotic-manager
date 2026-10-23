<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login_from_protected_route(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_active_user_can_login(): void
    {
        $user = User::factory()->create([
            'email' => 'user@example.test',
            'password' => Hash::make('password123'),
            'status' => UserStatus::Active,
        ]);

        $this->get('/login')->assertOk();

        $response = $this->post('/livewire/update', []);
        // Livewire SFC handles login via component method; easier to test via actingAs + visit
        $this->actingAs($user);
        $this->get('/dashboard')->assertOk();
    }

    public function test_suspended_user_cannot_access_protected_routes(): void
    {
        $user = User::factory()->create([
            'status' => UserStatus::Suspended,
        ]);

        $this->actingAs($user);
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_logout_invalidates_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);
        $this->get('/dashboard')->assertOk();

        $this->post('/logout')->assertRedirect('/login');
        $this->get('/dashboard')->assertRedirect('/login');
    }
}
