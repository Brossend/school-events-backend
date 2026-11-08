<?php

namespace Database\Seeders;

use App\Models\CalendarEvent;
use App\Models\CulturalClaim;
use App\Models\CulturalClaimSource;
use App\Models\EventOccurrence;
use App\Models\EventSource;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\SchoolTradition;
use App\Models\TraditionVersion;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ProductionDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (config('llm.provider') !== 'mock') {
            throw new \RuntimeException('Synthetic deployment data requires MockProvider.');
        }
        $passwords = [];
        foreach (['ADMIN', 'TEACHER', 'DIRECTOR'] as $role) {
            $password = env('DEMO_'.$role.'_PASSWORD');
            if (! is_string($password) || strlen($password) < 24) {
                throw new \RuntimeException('Configure strong deployment demo credentials before seeding.');
            }
            $passwords[$role] = $password;
        }
        DB::transaction(function () use ($passwords): void {
            User::firstOrCreate(['email' => 'admin@demo.school-events.ru'], [
                'name' => 'ДЕМО: системный администратор', 'password' => Hash::make($passwords['ADMIN']),
                'system_role' => 'system_admin', 'is_active' => true,
            ]);
            foreach (['alpha', 'beta'] as $tenant) {
                $school = School::firstOrCreate(['name' => 'ДЕМО: синтетическая школа '.$tenant], [
                    'short_name' => 'ДЕМО '.$tenant, 'region_code' => '66', 'city' => 'Демонстрационный город',
                    'settings' => ['brand_title' => 'ДЕМО: только синтетические данные'],
                ]);
                foreach (['TEACHER' => 'teacher', 'DIRECTOR' => 'school_admin'] as $credential => $role) {
                    $user = User::firstOrCreate(['email' => strtolower($credential).'.'.$tenant.'@demo.school-events.ru'], [
                        'name' => 'ДЕМО: '.strtolower($credential).' '.$tenant,
                        'password' => Hash::make($passwords[$credential]), 'system_role' => 'none', 'is_active' => true,
                    ]);
                    SchoolMembership::firstOrCreate(['school_id' => $school->id, 'user_id' => $user->id], [
                        'role' => $role, 'is_default' => true,
                    ]);
                }
                $teacher = User::where('email', 'teacher.'.$tenant.'@demo.school-events.ru')->firstOrFail();
                $event = CalendarEvent::firstOrCreate(['school_id' => $school->id, 'title' => 'ДЕМО: день взаимопомощи'], [
                    'event_type' => 'school_custom', 'date_type' => 'fixed_annual', 'fixed_month' => 11, 'fixed_day' => 10,
                    'description' => 'Синтетическое событие для проверки deployment. Не реальное мероприятие.',
                    'target_age_groups' => ['basic_5_9'], 'recommended_value_directions' => ['mutual_aid'], 'status' => 'approved',
                ]);
                EventOccurrence::firstOrCreate(['calendar_event_id' => $event->id, 'academic_year' => '2026-2027'], [
                    'start_date' => '2026-11-10', 'end_date' => '2026-11-10',
                ]);
                $tradition = SchoolTradition::firstOrCreate(['school_id' => $school->id, 'title' => 'ДЕМО: круг взаимопомощи'], [
                    'description' => 'Синтетическая школьная традиция.', 'established_year' => 2026, 'created_by_user_id' => $teacher->id,
                ]);
                TraditionVersion::firstOrCreate(['tradition_id' => $tradition->id, 'academic_year' => '2026-2027'], [
                    'parameters_snapshot' => ['duration_minutes' => 40, 'participants_count' => 25],
                    'notes' => 'ДЕМО: не педагогическая экспертиза.', 'created_by_user_id' => $teacher->id,
                ]);
            }
            $event = CalendarEvent::firstOrCreate(['title' => 'ДЕМО: непроверенная культурная фикстура', 'school_id' => null], [
                'event_type' => 'cultural_traditional', 'date_type' => 'fixed_annual', 'fixed_month' => 11, 'fixed_day' => 11,
                'description' => 'Вымышленный материал. Не этнографический источник.',
                'target_age_groups' => ['basic_5_9'], 'recommended_value_directions' => ['cultural_diversity'], 'status' => 'approved',
            ]);
            EventOccurrence::firstOrCreate(['calendar_event_id' => $event->id, 'academic_year' => '2026-2027'], [
                'start_date' => '2026-11-11', 'end_date' => '2026-11-11',
            ]);
            $source = EventSource::firstOrCreate(['calendar_event_id' => $event->id, 'title' => 'ДЕМО: источник без экспертизы'], [
                'source_type' => 'ethnographic_archive', 'author' => 'Синтетическая фикстура',
            ]);
            $claim = CulturalClaim::firstOrCreate(['calendar_event_id' => $event->id, 'claim_text' => 'ДЕМО: вымышленное утверждение, использование запрещено.'], [
                'culture_name' => 'Синтетическая культура', 'provenance_notes' => 'Не проверено, не использовать как факт.',
            ]);
            CulturalClaimSource::firstOrCreate(['cultural_claim_id' => $claim->id, 'event_source_id' => $source->id], [
                'calendar_event_id' => $event->id, 'citation_locator' => 'ДЕМО', 'verification_status' => 'unverified',
                'applicable_academic_year' => '2026-2027', 'notes' => 'ДЕМО: проверка запрета обхода Provenance Gate.',
            ]);
        });
    }
}
