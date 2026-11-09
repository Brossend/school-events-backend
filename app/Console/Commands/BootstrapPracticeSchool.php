<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class BootstrapPracticeSchool extends Command
{
    protected $signature = 'practice:bootstrap {--email=} {--school=} {--name=Администратор школы}';

    protected $description = 'Create the first school admin in an empty isolated development database; asks for password privately';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing']) || DB::connection()->getDriverName() !== 'pgsql' || ! preg_match('/\Aschool_events_ux001[a-z0-9_]*\z/', DB::selectOne('SELECT current_database() AS name', [], false)->name)) {
            $this->error('Refused: bootstrap is restricted to a local/testing school_events_ux001* database.');

            return self::FAILURE;
        }
        if (User::exists() || School::exists()) {
            $this->error('Refused: users/schools must be empty. Use invitations for subsequent accounts.');

            return self::FAILURE;
        }
        $data = ['email' => mb_strtolower(trim((string) $this->option('email'))), 'school' => trim((string) $this->option('school')),
            'name' => trim((string) $this->option('name')), 'password' => $this->secret('New local administrator password (not logged)', false)];
        $validation = Validator::make($data, ['email' => 'required|email|max:255', 'school' => 'required|string|max:255',
            'name' => 'required|string|max:255', 'password' => ['required', Password::min(12)->mixedCase()->numbers()]]);
        if ($validation->fails()) {
            $this->error('Invalid email, school or password (12+ characters with upper/lower case and numbers). No changes.');

            return self::FAILURE;
        }
        $created = DB::transaction(function () use ($data): bool {
            DB::select("SELECT pg_advisory_xact_lock(hashtextextended('ux001-bootstrap', 0))", [], false);
            if (User::exists() || School::exists()) {
                return false;
            }
            $school = School::create(['name' => $data['school'], 'region_code' => '66', 'city' => 'Не указан', 'settings' => []]);
            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'], 'system_role' => 'none', 'is_active' => true]);
            SchoolMembership::create(['user_id' => $user->id, 'school_id' => $school->id, 'role' => 'school_admin', 'is_default' => true]);

            return true;
        });
        if (! $created) {
            $this->error('Refused: bootstrap already completed by another process. No changes.');

            return self::FAILURE;
        }
        $this->info('School administrator created. Use the invitation page to register teachers.');

        return self::SUCCESS;
    }
}
