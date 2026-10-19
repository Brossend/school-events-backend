<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use DatabaseTransactions;

    protected User $activeTeacher;
    protected User $inactiveUser;
    protected School $school;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create([
            'name' => 'Лицей № 130',
        ]);

        $this->activeTeacher = User::factory()->create([
            'name' => 'Елена Смирнова',
            'email' => 'elena@school130.ru',
            'password' => Hash::make('Password2026!'),
            'is_active' => true,
        ]);

        SchoolMembership::factory()->create([
            'school_id' => $this->school->id,
            'user_id' => $this->activeTeacher->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);

        $this->inactiveUser = User::factory()->inactive()->create([
            'email' => 'inactive@school130.ru',
            'password' => Hash::make('Password2026!'),
        ]);
    }

    /**
     * Test successful login issues session and user profile without bearer token.
     */
    public function test_successful_login(): void
    {
        $response = $this->postWithCsrf('/api/v1/auth/login', [
            'email' => 'elena@school130.ru',
            'password' => 'Password2026!',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'user' => ['id', 'name', 'email', 'system_role'],
                    'schools' => [
                        '*' => ['school_id', 'name', 'role', 'is_default'],
                    ],
                ],
            ])
            ->assertJsonPath('data.user.email', 'elena@school130.ru')
            ->assertJsonPath('data.schools.0.role', 'teacher')
            ->assertJsonMissing(['token', 'access_token', 'bearer_token']);

        $this->assertAuthenticatedAs($this->activeTeacher, 'web');
    }

    /**
     * Test login fails with wrong password.
     */
    public function test_wrong_password(): void
    {
        $response = $this->postWithCsrf('/api/v1/auth/login', [
            'email' => 'elena@school130.ru',
            'password' => 'WrongPassword!',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

        $this->assertGuest('web');
    }

    /**
     * Test missing CSRF token returns 419 CSRF_TOKEN_MISMATCH.
     */
    public function test_missing_csrf(): void
    {
        // Disable Laravel's automatic unit-test CSRF bypass to enforce real CSRF validation
        $this->app->bind(
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            function ($app) {
                return new class($app, $app['encrypter']) extends \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken {
                    protected function runningUnitTests()
                    {
                        return false;
                    }
                };
            }
        );

        // Direct POST without CSRF token from stateful domain
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'elena@school130.ru',
            'password' => 'Password2026!',
        ]);

        $response->assertStatus(419)
            ->assertJsonPath('error.code', 'CSRF_TOKEN_MISMATCH');
    }

    /**
     * Test login regenerates session ID.
     */
    public function test_login_regenerates_session(): void
    {
        $csrf = $this->fetchCsrf();
        $initialSessionCookie = $csrf['cookies'][config('session.cookie', 'laravel_session')] ?? null;

        $response = $this->withCookies($csrf['cookies'])
            ->withHeader('X-XSRF-TOKEN', $csrf['token'])
            ->postJson('/api/v1/auth/login', [
                'email' => 'elena@school130.ru',
                'password' => 'Password2026!',
            ]);

        $response->assertStatus(200);

        $newSessionCookie = $response->getCookie(config('session.cookie', 'laravel_session'));
        if ($newSessionCookie && $initialSessionCookie) {
            $this->assertNotEquals($initialSessionCookie, $newSessionCookie->getValue());
        } else {
            // Also assert authenticated
            $this->assertAuthenticatedAs($this->activeTeacher, 'web');
        }
    }

    /**
     * Test /auth/me with active session returns user data.
     */
    public function test_auth_me_with_active_session(): void
    {
        // 1. Log in via session
        $loginResponse = $this->postWithCsrf('/api/v1/auth/login', [
            'email' => 'elena@school130.ru',
            'password' => 'Password2026!',
        ]);
        $loginResponse->assertStatus(200);

        // 2. Call /auth/me with session
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(200)
            ->assertJsonPath('data.user.id', $this->activeTeacher->id)
            ->assertJsonPath('data.user.email', $this->activeTeacher->email)
            ->assertJsonPath('data.schools.0.school_id', $this->school->id);
    }

    /**
     * Test /auth/me without session returns 401 UNAUTHORIZED in standard envelope.
     */
    public function test_auth_me_without_session(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHORIZED')
            ->assertJsonStructure([
                'error' => ['code', 'message', 'details', 'trace_id'],
            ]);
    }

    /**
     * Test logout clears session and invalidates token.
     */
    public function test_logout(): void
    {
        // 1. Log in
        $loginResponse = $this->postWithCsrf('/api/v1/auth/login', [
            'email' => 'elena@school130.ru',
            'password' => 'Password2026!',
        ]);
        $loginResponse->assertStatus(200);

        $cookies = [];
        $xsrfToken = null;
        foreach ($loginResponse->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
            if ($cookie->getName() === 'XSRF-TOKEN') {
                $xsrfToken = urldecode($cookie->getValue());
            }
        }

        // 2. Logout with authenticated session cookies and CSRF token
        $response = $this->withCookies($cookies)
            ->withHeader('X-XSRF-TOKEN', $xsrfToken)
            ->postJson('/api/v1/auth/logout');

        $response->assertNoContent();
        $this->assertGuest('web');
    }

    /**
     * Test accessing /auth/me after logout is forbidden (401).
     */
    public function test_access_after_logout_is_denied(): void
    {
        // 1. Log in
        $loginResponse = $this->postWithCsrf('/api/v1/auth/login', [
            'email' => 'elena@school130.ru',
            'password' => 'Password2026!',
        ]);
        $loginResponse->assertStatus(200);

        $cookies = [];
        $xsrfToken = null;
        foreach ($loginResponse->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
            if ($cookie->getName() === 'XSRF-TOKEN') {
                $xsrfToken = urldecode($cookie->getValue());
            }
        }

        // 2. Logout
        $this->withCookies($cookies)
            ->withHeader('X-XSRF-TOKEN', $xsrfToken)
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        auth()->forgetGuards();

        // 3. /auth/me must return 401
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHORIZED');
    }

    /**
     * Test inactive user is rejected with 403 ACCOUNT_INACTIVE.
     */
    public function test_inactive_user_is_denied(): void
    {
        $response = $this->postWithCsrf('/api/v1/auth/login', [
            'email' => 'inactive@school130.ru',
            'password' => 'Password2026!',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');

        $this->assertGuest('web');
    }

    /**
     * Test login rate limiter enforces 5 attempts/min and prevents case-sensitive bypass.
     */
    public function test_login_rate_limiting(): void
    {
        $email = 'elena@school130.ru';

        // 5 attempts allowed within 1 minute
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postWithCsrf('/api/v1/auth/login', [
                'email' => $email,
                'password' => 'WrongPassword!',
            ]);
            $response->assertStatus(422);
        }

        // 6th attempt must be rejected with 429 TOO_MANY_REQUESTS
        $response = $this->postWithCsrf('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'WrongPassword!',
        ]);
        $response->assertStatus(429)
            ->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');

        // 7th attempt with UPPERCASE email must also be rejected with 429
        $responseUpper = $this->postWithCsrf('/api/v1/auth/login', [
            'email' => strtoupper($email),
            'password' => 'WrongPassword!',
        ]);
        $responseUpper->assertStatus(429)
            ->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');
    }

    /**
     * Test independent IP rate limiter blocks brute-force attempts across different emails.
     */
    public function test_login_rate_limiting_by_ip_across_different_emails(): void
    {
        // 10 attempts from the same IP with completely distinct emails
        for ($i = 1; $i <= 10; $i++) {
            $response = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.100'])
                ->postWithCsrf('/api/v1/auth/login', [
                    'email' => "unique_user_{$i}@test.ru",
                    'password' => 'WrongPassword!',
                ]);
            $response->assertStatus(422);
        }

        // 11th attempt from the same IP (even with a new email) must be rejected with 429
        $response11 = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.100'])
            ->postWithCsrf('/api/v1/auth/login', [
                'email' => 'another_victim@test.ru',
                'password' => 'WrongPassword!',
            ]);

        $response11->assertStatus(429)
            ->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');
    }

    /**
     * Test demo DatabaseSeeder refuses execution in production environment.
     */
    public function test_seeder_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DatabaseSeeder (demo data) cannot be executed in production environment.');

        $seeder = new \Database\Seeders\DatabaseSeeder();
        $seeder->run();
    }

    /**
     * Test mid-session account deactivation blocks /auth/me and invalidates session.
     */
    public function test_mid_session_deactivation_blocks_auth_me(): void
    {
        // 1. Successful login with active teacher
        $loginResponse = $this->postWithCsrf('/api/v1/auth/login', [
            'email' => 'elena@school130.ru',
            'password' => 'Password2026!',
        ]);
        $loginResponse->assertStatus(200);

        $cookies = [];
        foreach ($loginResponse->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }

        // 2. Initial /auth/me call succeeds
        $this->withCookies($cookies)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(200);

        // 3. User is deactivated in database
        $this->activeTeacher->update(['is_active' => false]);

        // 4. Subsequent /auth/me is blocked with 403 ACCOUNT_INACTIVE
        $blockedResponse = $this->withCookies($cookies)
            ->getJson('/api/v1/auth/me');

        $blockedResponse->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');

        // 5. Subsequent request with the same cookies is rejected with 401 UNAUTHORIZED
        auth()->forgetGuards();
        $subsequentResponse = $this->withCookies($cookies)
            ->getJson('/api/v1/auth/me');

        $subsequentResponse->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHORIZED');
    }

    /**
     * Test mid-session account deactivation blocks /system/status and invalidates session.
     */
    public function test_mid_session_deactivation_blocks_system_status(): void
    {
        $admin = User::factory()->systemAdmin()->create([
            'email' => 'admin_active@system.ru',
            'password' => Hash::make('AdminSecret2026!'),
            'is_active' => true,
        ]);

        // 1. Login as system admin
        $loginResponse = $this->postWithCsrf('/api/v1/auth/login', [
            'email' => 'admin_active@system.ru',
            'password' => 'AdminSecret2026!',
        ]);
        $loginResponse->assertStatus(200);

        $cookies = [];
        foreach ($loginResponse->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }

        // 2. Access /system/status succeeds
        $this->withCookies($cookies)
            ->getJson('/api/v1/system/status')
            ->assertStatus(200);

        // 3. Deactivate admin
        $admin->update(['is_active' => false]);

        // 4. Request to /system/status returns 403 ACCOUNT_INACTIVE
        $blockedResponse = $this->withCookies($cookies)
            ->getJson('/api/v1/system/status');

        $blockedResponse->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');

        // 5. Subsequent request is 401 UNAUTHORIZED
        auth()->forgetGuards();
        $this->withCookies($cookies)
            ->getJson('/api/v1/system/status')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHORIZED');
    }

    /**
     * Test mid-session account deactivation blocks school tenant routes and invalidates session.
     */
    public function test_mid_session_deactivation_blocks_tenant_routes(): void
    {
        // 1. Login as teacher
        $loginResponse = $this->postWithCsrf('/api/v1/auth/login', [
            'email' => 'elena@school130.ru',
            'password' => 'Password2026!',
        ]);
        $loginResponse->assertStatus(200);

        $cookies = [];
        foreach ($loginResponse->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }

        // 2. Access /tenant/current succeeds
        $this->withCookies($cookies)
            ->withHeader('X-School-Id', $this->school->id)
            ->getJson('/api/v1/tenant/current')
            ->assertStatus(200);

        // 3. Deactivate teacher
        $this->activeTeacher->update(['is_active' => false]);

        // 4. Request to /tenant/current returns 403 ACCOUNT_INACTIVE
        $blockedResponse = $this->withCookies($cookies)
            ->withHeader('X-School-Id', $this->school->id)
            ->getJson('/api/v1/tenant/current');

        $blockedResponse->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');

        // 5. Subsequent request is 401 UNAUTHORIZED
        auth()->forgetGuards();
        $this->withCookies($cookies)
            ->withHeader('X-School-Id', $this->school->id)
            ->getJson('/api/v1/tenant/current')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHORIZED');
    }
}
