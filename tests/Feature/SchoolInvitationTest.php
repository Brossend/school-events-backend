<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\SchoolInvitation;
use App\Models\SchoolMembership;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SchoolInvitationTest extends TestCase
{
    use DatabaseTransactions;

    private function administrator(): array
    {
        $school = School::factory()->create();
        $user = User::factory()->create();
        SchoolMembership::factory()->create(['school_id' => $school->id, 'user_id' => $user->id, 'role' => 'school_admin']);

        return [$user, $school];
    }

    private function invitation(School $school, User $creator, array $attributes = []): array
    {
        $code = bin2hex(random_bytes(32));
        $invitation = SchoolInvitation::create(array_merge(['school_id' => $school->id, 'created_by' => $creator->id,
            'role' => 'teacher', 'code_hash' => hash('sha256', $code), 'expires_at' => now()->addDays(7)], $attributes));

        return [$invitation, $code];
    }

    private function registration(string $code, array $attributes = []): array
    {
        return array_merge(['invitation_code' => $code, 'name' => 'Педагог проверки',
            'email' => 'new.teacher@example.test', 'password' => 'OnlyForTests2026!', 'password_confirmation' => 'OnlyForTests2026!'], $attributes);
    }

    public function test_school_admin_receives_code_once_and_list_never_exposes_hash_or_code(): void
    {
        $this->travelTo(now()->startOfSecond());
        [$admin, $school] = $this->administrator();

        $response = $this->actingAs($admin)->postJson("/api/v1/schools/{$school->id}/invitations", ['email' => ' Person@Example.test ']);

        $response->assertCreated()->assertJsonPath('data.invitation.role', 'teacher')->assertJsonPath('data.invitation.email', 'person@example.test');
        $code = $response->json('data.code');
        $this->assertSame(64, strlen($code));
        $invitation = SchoolInvitation::findOrFail($response->json('data.invitation.id'));
        $this->assertSame(hash('sha256', $code), $invitation->code_hash);
        $this->assertTrue($invitation->expires_at->equalTo(now()->addDays(7)));
        $response->assertHeader('Cache-Control', 'no-store, private');
        $list = $this->getJson("/api/v1/schools/{$school->id}/invitations");
        $list->assertOk()->assertDontSee($code)->assertDontSee('code_hash');
    }

    public function test_teacher_cannot_create_or_list_or_revoke_invitations(): void
    {
        [$admin, $school] = $this->administrator();
        [$invitation] = $this->invitation($school, $admin);
        $teacher = User::factory()->create();
        SchoolMembership::factory()->create(['user_id' => $teacher->id, 'school_id' => $school->id, 'role' => 'teacher']);

        $this->assertFalse(Gate::forUser($teacher)->allows('manage-school-invitations', $school));
        $this->actingAs($teacher)->getJson("/api/v1/schools/{$school->id}/invitations")->assertForbidden();
        $this->postJson("/api/v1/schools/{$school->id}/invitations", [])->assertForbidden();
        $this->postJson("/api/v1/schools/{$school->id}/invitations/{$invitation->id}/revoke")->assertForbidden();
        $this->assertNull($invitation->fresh()->revoked_at);
    }

    public function test_foreign_administrator_cannot_manage_another_school(): void
    {
        [$admin] = $this->administrator();
        [$owner, $school] = $this->administrator();
        [$invitation] = $this->invitation($school, $owner);

        $this->actingAs($admin)->getJson("/api/v1/schools/{$school->id}/invitations")->assertForbidden();
        $this->postJson("/api/v1/schools/{$school->id}/invitations/{$invitation->id}/revoke")->assertForbidden();
        $this->assertNull($invitation->fresh()->revoked_at);
    }

    public function test_system_admin_can_manage_and_curator_cannot(): void
    {
        $school = School::factory()->create();
        $system = User::factory()->create(['system_role' => 'system_admin']);
        $curator = User::factory()->create(['system_role' => 'catalog_curator']);

        $this->assertTrue(Gate::forUser($system)->allows('manage-school-invitations', $school));
        $this->assertFalse(Gate::forUser($curator)->allows('manage-school-invitations', $school));
        $this->actingAs($system)->postJson("/api/v1/schools/{$school->id}/invitations", [])->assertCreated();
    }

    public function test_guest_cannot_manage_and_extra_privilege_fields_are_rejected(): void
    {
        [$admin, $school] = $this->administrator();
        $before = SchoolInvitation::count();

        $this->getJson("/api/v1/schools/{$school->id}/invitations")->assertUnauthorized();
        $this->actingAs($admin)->postJson("/api/v1/schools/{$school->id}/invitations", ['role' => 'school_admin'])->assertUnprocessable();
        $this->assertDatabaseCount('school_invitations', $before);
    }

    public function test_registration_consumes_invitation_creates_only_teacher_and_can_login(): void
    {
        [$admin, $school] = $this->administrator();
        [$invitation, $code] = $this->invitation($school, $admin, ['email' => 'new.teacher@example.test']);

        $this->postJson('/api/v1/auth/register', $this->registration($code, ['email' => 'NEW.Teacher@example.test']))->assertCreated()->assertJsonPath('data.registered', true);

        $teacher = User::where('email', 'new.teacher@example.test')->firstOrFail();
        $this->assertSame('none', $teacher->system_role);
        $this->assertTrue(Hash::check('OnlyForTests2026!', $teacher->password));
        $this->assertDatabaseHas('school_memberships', ['user_id' => $teacher->id, 'school_id' => $school->id, 'role' => 'teacher', 'is_default' => true]);
        $this->assertNotNull($invitation->fresh()->used_at);
        $this->postWithCsrf('/api/v1/auth/login', ['email' => $teacher->email, 'password' => 'OnlyForTests2026!'])->assertOk();
    }

    public function test_replay_is_conflict_and_does_not_create_second_membership(): void
    {
        [$admin, $school] = $this->administrator();
        [$invitation, $code] = $this->invitation($school, $admin);
        $this->postJson('/api/v1/auth/register', $this->registration($code))->assertCreated();

        $this->postJson('/api/v1/auth/register', $this->registration($code, ['email' => 'another@example.test']))->assertConflict();

        $this->assertSame(1, SchoolMembership::where('school_id', $school->id)->where('role', 'teacher')->count());
        $this->assertDatabaseMissing('users', ['email' => 'another@example.test']);
    }

    public function test_existing_user_cannot_gain_membership_and_error_is_generic(): void
    {
        [$admin, $school] = $this->administrator();
        [$invitation, $code] = $this->invitation($school, $admin);
        $user = User::factory()->create(['email' => 'Existing@Example.test']);

        $response = $this->postJson('/api/v1/auth/register', $this->registration($code, ['email' => 'existing@example.test']));

        $response->assertConflict()->assertJsonPath('error.code', 'REGISTRATION_CONFLICT');
        $this->assertDatabaseMissing('school_memberships', ['user_id' => $user->id]);
        $this->assertNull($invitation->fresh()->used_at);
    }

    public function test_expired_revoked_unknown_and_email_mismatch_are_rejected_without_consuming(): void
    {
        [$admin, $school] = $this->administrator();
        [$expired, $code] = $this->invitation($school, $admin, ['expires_at' => now()->subSecond()]);
        $this->postJson('/api/v1/auth/register', $this->registration($code))->assertUnprocessable()->assertJsonPath('error.code', 'INVITATION_INVALID');
        [$bound, $boundCode] = $this->invitation($school, $admin, ['email' => 'different@example.test']);
        $this->postJson('/api/v1/auth/register', $this->registration($boundCode))->assertUnprocessable();
        $this->postJson('/api/v1/auth/register', $this->registration(str_repeat('a', 64)))->assertUnprocessable();
        $this->assertNull($expired->fresh()->used_at);
        $this->assertNull($bound->fresh()->used_at);
        $this->assertDatabaseMissing('users', ['email' => 'new.teacher@example.test']);
    }

    public function test_revoke_is_idempotent_and_prevents_registration(): void
    {
        [$admin, $school] = $this->administrator();
        [$invitation, $code] = $this->invitation($school, $admin);
        $url = "/api/v1/schools/{$school->id}/invitations/{$invitation->id}/revoke";
        $this->actingAs($admin)->postJson($url)->assertOk();
        $revoked = $invitation->fresh()->revoked_at;
        $this->postJson($url)->assertOk();
        $this->assertTrue($invitation->fresh()->revoked_at->equalTo($revoked));
        $this->postJson('/api/v1/auth/register', $this->registration($code))->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['email' => 'new.teacher@example.test']);
    }

    public function test_registration_rejects_role_injection_and_mismatched_password_without_using_code(): void
    {
        [$admin, $school] = $this->administrator();
        [$invitation, $code] = $this->invitation($school, $admin);
        $this->postJson('/api/v1/auth/register', $this->registration($code, ['system_role' => 'system_admin']))->assertUnprocessable();
        $this->postJson('/api/v1/auth/register', $this->registration($code, ['school_id' => $school->id]))->assertUnprocessable();
        $this->postJson('/api/v1/auth/register', $this->registration($code, ['password_confirmation' => 'Different2026!']))->assertUnprocessable();
        $this->assertNull($invitation->fresh()->used_at);
        $this->assertDatabaseMissing('users', ['email' => 'new.teacher@example.test']);
    }

    public function test_registration_rate_limit_prevents_guessing(): void
    {
        [$admin, $school] = $this->administrator();
        [, $code] = $this->invitation($school, $admin);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/register', $this->registration(str_repeat('b', 64)))->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/register', $this->registration($code))->assertTooManyRequests();
        $this->assertDatabaseMissing('users', ['email' => 'new.teacher@example.test']);
    }

    public function test_invitation_expiring_now_is_invalid_and_used_invitation_cannot_be_revoked(): void
    {
        $this->travelTo(now()->startOfSecond());
        [$admin, $school] = $this->administrator();
        [$expired, $code] = $this->invitation($school, $admin, ['expires_at' => now()]);
        $this->postJson('/api/v1/auth/register', $this->registration($code))->assertUnprocessable();
        $this->assertNull($expired->fresh()->used_at);
        [$used] = $this->invitation($school, $admin);
        $used->forceFill(['used_at' => now()])->save();
        $this->actingAs($admin)->postJson("/api/v1/schools/{$school->id}/invitations/{$used->id}/revoke")->assertConflict();
        $this->assertNull($used->fresh()->revoked_at);
    }

    public function test_stateful_registration_requires_csrf_and_accepts_valid_csrf(): void
    {
        $this->app->bind(ValidateCsrfToken::class, function ($app) {
            return new class($app, $app['encrypter']) extends ValidateCsrfToken
            {
                protected function runningUnitTests()
                {
                    return false;
                }
            };
        });
        [$admin, $school] = $this->administrator();
        [$invitation, $code] = $this->invitation($school, $admin);
        $this->postJson('/api/v1/auth/register', $this->registration($code))->assertStatus(419);
        $this->assertNull($invitation->fresh()->used_at);
        $this->postWithCsrf('/api/v1/auth/register', $this->registration($code))->assertCreated();
    }

    public function test_foreign_invitation_cannot_be_revoked_under_owned_school(): void
    {
        [$admin, $school] = $this->administrator();
        [$owner, $foreignSchool] = $this->administrator();
        [$invitation] = $this->invitation($foreignSchool, $owner);

        $this->actingAs($admin)->postJson("/api/v1/schools/{$school->id}/invitations/{$invitation->id}/revoke")->assertNotFound();

        $this->assertNull($invitation->fresh()->revoked_at);
    }

    public function test_guest_cannot_manage_invitations(): void
    {
        [$admin, $school] = $this->administrator();
        [$invitation] = $this->invitation($school, $admin);

        $this->getJson("/api/v1/schools/{$school->id}/invitations")->assertUnauthorized();
        $this->postJson("/api/v1/schools/{$school->id}/invitations", [])->assertUnauthorized();
        $this->postJson("/api/v1/schools/{$school->id}/invitations/{$invitation->id}/revoke")->assertUnauthorized();

        $this->assertNull($invitation->fresh()->revoked_at);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_fresh_and_upgrade_migrations_preserve_existing_users(bool $fresh): void
    {
        $schema = 'ux001_migration_'.bin2hex(random_bytes(8));
        config(['database.connections.migration_admin' => config('database.connections.pgsql')]);
        $schemaAdmin = DB::connection('migration_admin');
        $schemaAdmin->statement('CREATE SCHEMA '.$schema);
        config(['database.connections.migration_review' => array_merge(config('database.connections.pgsql'), ['search_path' => $schema])]);
        $connection = DB::connection('migration_review');
        try {
            $options = ['--database' => 'migration_review', '--force' => true, '--no-interaction' => true];
            if (! $fresh) {
                $paths = collect(glob(database_path('migrations/*.php')))
                    ->filter(fn (string $path): bool => basename($path) < '2026_11_09')->all();
                $this->assertSame(0, Artisan::call('migrate', $options + ['--path' => $paths, '--realpath' => true]), Artisan::output());
                $connection->table('users')->insert(['id' => (string) Str::uuid(), 'name' => 'Existing teacher',
                    'email' => 'migration@example.test', 'password' => Hash::make('OnlyForTests2026!'), 'system_role' => 'none', 'is_active' => true]);
            }

            $this->assertSame(0, Artisan::call('migrate', $options), Artisan::output());
            $this->assertTrue($connection->getSchemaBuilder()->hasTable('school_invitations'));
            $this->assertSame(0, Artisan::call('migrate', $options), Artisan::output());
            if (! $fresh) {
                $this->assertSame('Existing teacher', $connection->table('users')->where('email', 'migration@example.test')->value('name'));
            }
        } finally {
            DB::purge('migration_review');
            $schemaAdmin->statement('DROP SCHEMA '.$schema.' CASCADE');
            DB::purge('migration_admin');
        }
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_concurrent_registration_returns_one_201_and_one_409(bool $sameInvitation): void
    {
        config(['database.connections.invitation_concurrency' => config('database.connections.pgsql')]);
        $connection = DB::connection('invitation_concurrency');
        $admin = User::factory()->connection('invitation_concurrency')->create();
        $school = School::factory()->connection('invitation_concurrency')->create();
        $email = 'concurrent-'.$school->id.'@example.test';
        $otherEmail = 'second-'.$school->id.'@example.test';
        $codes = [bin2hex(random_bytes(32)), bin2hex(random_bytes(32))];
        $processes = [];

        try {
            foreach ($codes as $code) {
                SchoolInvitation::on('invitation_concurrency')->create(['school_id' => $school->id, 'created_by' => $admin->id,
                    'role' => 'teacher', 'code_hash' => hash('sha256', $code), 'expires_at' => now()->addDay()]);
            }
            $worker = <<<'PHP'
            require "vendor/autoload.php";
            $app = require "bootstrap/app.php";
            $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
            $kernel->bootstrap();
            config(["cache.default" => "array", "session.driver" => "array"]);
            echo "READY\n"; flush();
            fgets(STDIN);
            $request = Illuminate\Http\Request::create("/api/v1/auth/register", "POST", [], [], [],
                ["CONTENT_TYPE" => "application/json", "HTTP_ACCEPT" => "application/json"], $argv[1]);
            $response = $kernel->handle($request);
            echo $response->getStatusCode();
            $kernel->terminate($request, $response);
            PHP;
            $inputs = [];
            foreach ([0, 1] as $index) {
                $payload = $this->registration($codes[$sameInvitation ? 0 : $index], ['email' => $sameInvitation && $index === 1 ? $otherEmail : $email]);
                $process = new Process([PHP_BINARY, '-r', $worker, json_encode($payload)], base_path(), timeout: 300);
                $inputs[$index] = new InputStream;
                $process->setInput($inputs[$index]);
                $process->start();
                $processes[] = $process;
            }
            foreach ($processes as $process) {
                $process->waitUntil(fn (string $type, string $output): bool => str_contains($process->getOutput(), 'READY'));
                $this->assertTrue($process->isRunning(), $process->getErrorOutput());
            }
            foreach ($inputs as $input) {
                $input->write("GO\n");
                $input->close();
            }
            $statuses = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $statuses[] = (int) substr(trim($process->getOutput()), -3);
            }
            sort($statuses);

            $this->assertSame([201, 409], $statuses);
            $this->assertSame(1, $connection->table('school_memberships')->where('school_id', $school->id)->count());
            $this->assertSame(1, $connection->table('school_invitations')->where('school_id', $school->id)->whereNotNull('used_at')->count());
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            $connection->table('school_memberships')->where('school_id', $school->id)->delete();
            $connection->table('school_invitations')->where('school_id', $school->id)->delete();
            $connection->table('users')->whereIn('email', [$email, $otherEmail])->delete();
            $school->delete();
            $admin->delete();
            DB::purge('invitation_concurrency');
        }
    }
}
