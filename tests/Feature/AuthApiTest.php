<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_register_without_being_logged_in(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ana Demo',
            'email' => 'ana@example.com',
            'password' => 'una-frase-segura-12',
            'password_confirmation' => 'una-frase-segura-12',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.email', 'ana@example.com');

        $this->assertGuest();
        $this->assertDatabaseHas('users', ['email' => 'ana@example.com']);
    }

    public function test_login_does_not_reveal_whether_the_email_exists(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'missing@example.com',
            'password' => 'cualquier-clave',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Las credenciales proporcionadas son incorrectas.');
    }

    public function test_verified_user_can_login_and_fetch_profile(): void
    {
        $user = User::factory()->create([
            'email' => 'dev@example.com',
            'password' => 'una-frase-segura-12',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'dev@example.com',
            'password' => 'una-frase-segura-12',
        ])->assertOk()->assertJsonPath('data.email', 'dev@example.com');

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Sesión cerrada correctamente.');

        $this->assertGuest('web');
    }

    public function test_email_can_be_verified_with_a_signed_link(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->getJson($url)->assertOk();

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_email_verification_rejects_unsigned_links(): void
    {
        $user = User::factory()->unverified()->create();

        $this->getJson("/api/v1/auth/email/verify/{$user->id}/".sha1($user->getEmailForVerification()))
            ->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_unverified_users_cannot_create_projects(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/projects', [
                'name' => 'Proyecto oculto',
            ])
            ->assertForbidden();
    }
}
