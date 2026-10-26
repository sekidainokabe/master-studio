<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'editor@example.com',
            'password' => 'secret-password',
            'role' => UserRole::Editor,
        ]);

        // Sanctum は SANCTUM_STATEFUL_DOMAINS から来たリクエストだけをセッション認証の対象にするため、SPA からのリクエストを装う
        $this->withHeader('Referer', 'http://localhost:5173');
    }

    public function test_login_with_valid_credentials_returns_user(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => 'editor@example.com',
            'password' => 'secret-password',
        ]);

        $response->assertOk()->assertExactJson([
            'data' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => 'editor@example.com',
                'role' => 'editor',
                'can' => ['create_changeset' => true],
            ],
        ]);
        $this->assertAuthenticatedAs($this->user, 'web');
    }

    public function test_login_with_wrong_password_fails_without_revealing_which_field_is_wrong(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => 'editor@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'メールアドレスまたはパスワードが違います。']);
        $this->assertGuest('web');
    }

    public function test_login_requires_email_and_password(): void
    {
        $this->postJson('/api/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_is_throttled_after_five_failed_attempts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['email' => 'editor@example.com', 'password' => 'wrong-password'])
                ->assertUnprocessable();
        }

        // 6回目は正しいパスワードでも拒否される
        $this->postJson('/api/login', ['email' => 'editor@example.com', 'password' => 'secret-password'])
            ->assertTooManyRequests();
    }

    public function test_me_returns_logged_in_user(): void
    {
        $this->actingAs($this->user, 'web')
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'editor@example.com')
            ->assertJsonPath('data.role', 'editor');
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_logout_ends_the_session(): void
    {
        $this->postJson('/api/login', [
            'email' => 'editor@example.com',
            'password' => 'secret-password',
        ])->assertOk();

        $this->postJson('/api/logout')->assertNoContent();

        $this->assertGuest('web');
    }
}
