<?php

declare(strict_types=1);

namespace IntrAgile\Manual\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use IntrAgile\Manual\Facades\Manual;
use IntrAgile\Manual\Tests\TestCase;

final class AccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['manual.path' => __DIR__.'/../Fixtures/manual', 'manual.locales' => ['en', 'hu'], 'manual.source_locale' => 'hu']);
    }

    public function test_disabled_is_404(): void
    {
        config(['manual.access' => 'disabled']);

        $this->getJson('/manual')->assertNotFound();
        $this->getJson('/manual/chapter?slug=general/intro')->assertNotFound();
        $this->getJson('/manual/search?q=abc')->assertNotFound();
    }

    public function test_access_applies_to_every_endpoint(): void
    {
        config(['manual.access' => 'authenticated']);

        $this->getJson('/manual/chapter?slug=general/intro')->assertUnauthorized();
        $this->getJson('/manual/search?q=abc')->assertUnauthorized();
        // Access is checked before validation, so a guest learns nothing from a 422.
        $this->getJson('/manual/search')->assertUnauthorized();

        $user = new GenericUser(['id' => 1]);
        $this->actingAs($user)->getJson('/manual/chapter?slug=general/intro')->assertOk();
        $this->actingAs($user)->getJson('/manual/search?q=abc')->assertOk();
    }

    public function test_gate_receives_the_user(): void
    {
        config(['manual.access' => 'view-manual']);
        Gate::define('view-manual', static fn (?GenericUser $user = null): bool => $user?->getAuthIdentifier() === 7);

        $this->actingAs(new GenericUser(['id' => 7]))->getJson('/manual')->assertOk();
        $this->actingAs(new GenericUser(['id' => 8]))->getJson('/manual')->assertForbidden();
    }

    public function test_authenticated_rejects_guests_with_401(): void
    {
        config(['manual.access' => 'authenticated']);

        $this->getJson('/manual')->assertUnauthorized();
    }

    public function test_authenticated_admits_users(): void
    {
        config(['manual.access' => 'authenticated']);

        $this->actingAs(new GenericUser(['id' => 1]))->getJson('/manual')->assertOk();
    }

    public function test_public_admits_guests(): void
    {
        config(['manual.access' => 'public']);

        $this->getJson('/manual')->assertOk();
    }

    public function test_gate_denying_a_user_is_403(): void
    {
        config(['manual.access' => 'view-manual']);
        Gate::define('view-manual', static fn (): bool => false);

        $this->actingAs(new GenericUser(['id' => 1]))->getJson('/manual')->assertForbidden();
    }

    public function test_gate_allowing_a_user_is_200(): void
    {
        config(['manual.access' => 'view-manual']);
        Gate::define('view-manual', static fn (): bool => true);

        $this->actingAs(new GenericUser(['id' => 1]))->getJson('/manual')->assertOk();
    }

    public function test_gate_denying_a_guest_is_401(): void
    {
        config(['manual.access' => 'view-manual']);
        Gate::define('view-manual', static fn (): bool => false);

        $this->getJson('/manual')->assertUnauthorized();
    }

    public function test_gate_is_consulted_for_guests(): void
    {
        config(['manual.access' => 'view-manual']);
        Gate::define('view-manual', static fn (?object $user = null): bool => true);

        $this->getJson('/manual')->assertOk();
    }

    public function test_undefined_gate_denies(): void
    {
        config(['manual.access' => 'no-such-gate']);

        $this->actingAs(new GenericUser(['id' => 1]))->getJson('/manual')->assertForbidden();
    }

    public function test_host_can_register_routes_in_its_own_group(): void
    {
        config(['manual.access' => 'public']);

        Route::middleware('api')->prefix('api/v1')->group(static fn () => Manual::routes());

        $this->getJson('/api/v1/manual')->assertOk();
        $this->getJson('/api/v1/manual/chapter?slug=general/intro')->assertOk()->assertJsonPath('data.slug', 'general/intro');
        $this->getJson('/api/v1/manual/search?q=abc')->assertOk();
    }

    public function test_host_group_still_gets_access_check_and_json_errors(): void
    {
        config(['manual.access' => 'authenticated']);

        Route::prefix('api/v1')->group(static fn () => Manual::routes());

        $this->getJson('/api/v1/manual')->assertUnauthorized();
        $this->actingAs(new GenericUser(['id' => 1]))
            ->get('/api/v1/manual/search')
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/json');
    }
}
