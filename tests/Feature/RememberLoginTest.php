<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class RememberLoginTest extends TestCase
{
    use DatabaseTransactions;

    public function test_unchecked_login_does_not_issue_a_remember_cookie(): void
    {
        $user = User::factory()->create(['password' => 'OnlyForTests2026!', 'remember_token' => null]);

        $response = $this->postWithCsrf('/api/v1/auth/login', ['email' => $user->email, 'password' => 'OnlyForTests2026!']);

        $response->assertOk()->assertCookieExpired(Auth::guard('web')->getRecallerName());
        $this->assertNull($user->fresh()->remember_token);
    }

    public function test_remember_cookie_has_30_day_lifetime_and_secure_session_attributes(): void
    {
        config(['session.secure' => true, 'session.same_site' => 'lax', 'session.http_only' => true]);
        $user = User::factory()->create(['password' => 'OnlyForTests2026!']);

        $response = $this->postWithCsrf('/api/v1/auth/login', ['email' => $user->email, 'password' => 'OnlyForTests2026!', 'remember' => true]);

        $response->assertOk();
        $cookie = $response->getCookie(Auth::guard('web')->getRecallerName());
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertEqualsWithDelta(30 * 24 * 60 * 60, $cookie->getMaxAge(), 5);
        $this->assertNotEmpty($user->fresh()->remember_token);
    }

    public function test_remember_restores_login_without_old_session_and_logout_revokes_old_recaller(): void
    {
        $user = User::factory()->create(['password' => 'OnlyForTests2026!']);
        $login = $this->postWithCsrf('/api/v1/auth/login', ['email' => $user->email, 'password' => 'OnlyForTests2026!', 'remember' => true]);
        $name = Auth::guard('web')->getRecallerName();
        $remember = $login->getCookie($name)->getValue();
        $token = $user->fresh()->remember_token;
        $this->flushSession();
        Auth::forgetGuards();

        $this->withCredentials()->withCookie($name, $remember)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.id', $user->id);
        $this->postWithCsrf('/api/v1/auth/logout')->assertNoContent()->assertCookieExpired($name);
        $this->assertNotSame($token, $user->fresh()->remember_token);

        $this->flushSession();
        Auth::forgetGuards();
        $this->withCredentials()->withCookie($name, $remember)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_remember_must_be_a_boolean(): void
    {
        $user = User::factory()->create(['password' => 'OnlyForTests2026!']);

        $this->postWithCsrf('/api/v1/auth/login', ['email' => $user->email, 'password' => 'OnlyForTests2026!', 'remember' => 'forever'])->assertUnprocessable();
    }

    public function test_unchecked_login_clears_previous_accounts_remember_cookie(): void
    {
        $first = User::factory()->create(['password' => 'OnlyForTests2026!']);
        $second = User::factory()->create(['password' => 'OnlyForTests2026!', 'remember_token' => null]);
        $login = $this->postWithCsrf('/api/v1/auth/login', ['email' => $first->email, 'password' => 'OnlyForTests2026!', 'remember' => true]);
        $name = Auth::guard('web')->getRecallerName();
        $remember = $login->getCookie($name)->getValue();
        $token = $first->fresh()->remember_token;

        $this->withCookie($name, $remember)->postWithCsrf('/api/v1/auth/login', ['email' => $second->email, 'password' => 'OnlyForTests2026!', 'remember' => false])
            ->assertOk()->assertJsonPath('data.user.id', $second->id)->assertCookieExpired($name);

        $this->assertNull($second->fresh()->remember_token);
        $this->assertSame($token, $first->fresh()->remember_token);
    }

    public function test_deactivated_user_cannot_restore_remembered_login_and_token_is_revoked(): void
    {
        $user = User::factory()->create(['password' => 'OnlyForTests2026!']);
        $login = $this->postWithCsrf('/api/v1/auth/login', ['email' => $user->email, 'password' => 'OnlyForTests2026!', 'remember' => true]);
        $name = Auth::guard('web')->getRecallerName();
        $remember = $login->getCookie($name)->getValue();
        $token = $user->fresh()->remember_token;
        $user->update(['is_active' => false]);
        $this->flushSession();
        Auth::forgetGuards();

        $this->withCredentials()->withCookie($name, $remember)->getJson('/api/v1/auth/me')
            ->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_INACTIVE')->assertCookieExpired($name);

        $this->assertNotSame($token, $user->fresh()->remember_token);
    }

    public function test_password_change_invalidates_old_remember_cookie(): void
    {
        $user = User::factory()->create(['password' => 'OnlyForTests2026!']);
        $login = $this->postWithCsrf('/api/v1/auth/login', ['email' => $user->email, 'password' => 'OnlyForTests2026!', 'remember' => true]);
        $name = Auth::guard('web')->getRecallerName();
        $remember = $login->getCookie($name)->getValue();
        $user->update(['password' => 'ChangedForTests2026!']);
        $this->flushSession();
        Auth::forgetGuards();

        $this->withCredentials()->withCookie($name, $remember)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_normalized_registration_email_can_be_used_to_log_in(): void
    {
        $user = User::factory()->create(['email' => 'teacher@example.test', 'password' => 'OnlyForTests2026!']);

        $this->postWithCsrf('/api/v1/auth/login', ['email' => ' Teacher@Example.test ', 'password' => 'OnlyForTests2026!'])
            ->assertOk()->assertJsonPath('data.user.id', $user->id);
    }
}
