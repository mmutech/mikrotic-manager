<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserStatus;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class AuthController extends Controller
{
    public function login(Request $request, AuditLogger $audit): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:80'],
        ]);

        $key = 'api-login:'.Str::lower(Str::transliterate((string) $validated['email'])).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'email' => "Too many sign-in attempts. Try again in {$seconds} seconds.",
            ]);
        }

        /** @var User|null $user */
        $user = User::query()->where('email', $validated['email'])->first();

        if ($user === null || ! Hash::check((string) $validated['password'], (string) $user->password)) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        if (! $user->isActive()) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'email' => 'This account is not active. Contact an administrator.',
            ]);
        }

        RateLimiter::clear($key);

        $user->forceFill(['last_login_at' => now()])->save();

        $token = $user->createToken(
            filled($validated['device_name'] ?? null) ? (string) $validated['device_name'] : 'mobile-app',
            ['*'],
        );

        $audit->record('auth.login', $user, metadata: [
            'email' => $user->email,
            'source' => 'mobile-api',
        ], actor: $user);

        return $this->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'abilities' => $token->accessToken->abilities,
            'user' => (new UserResource($user))->resolve($request),
        ], 'Signed in successfully.');
    }

    public function me(Request $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing(['roles', 'permissions']);

        return (new UserResource($user))->additional([
            'meta' => [
                'token_abilities' => $user->currentAccessToken()?->abilities ?? [],
            ],
        ]);
    }

    public function logout(Request $request, AuditLogger $audit): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $audit->record('auth.logout', $user, metadata: ['source' => 'mobile-api'], actor: $user);

        // Sign out destroys the token used for this request so it cannot be reused.
        $user->currentAccessToken()?->delete();

        // The token is gone, so a cached authenticated user must not survive
        // the rest of this request (or a reused application instance).
        Auth::guard('sanctum')->forgetUser();

        return $this->json([], 'Signed out successfully.', SymfonyResponse::HTTP_OK);
    }

    public function deactivateMe(Request $request, AuditLogger $audit): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $audit->record('auth.account_deactivated', $user, before: [
            'email' => $user->email,
            'status' => $user->status?->value,
        ], after: [
            'status' => UserStatus::Inactive->value,
        ], metadata: [
            'source' => 'mobile-api',
            'self_service' => true,
        ], actor: $user);

        // "Delete Account" is a self-service deactivation, not a record delete:
        // the account is kept for audit history and can be reactivated by an admin.
        $user->forceFill(['status' => UserStatus::Inactive])->save();
        $user->tokens()->delete();

        Auth::guard('sanctum')->forgetUser();

        return $this->json([], 'Account deactivated successfully.', SymfonyResponse::HTTP_OK);
    }
}
