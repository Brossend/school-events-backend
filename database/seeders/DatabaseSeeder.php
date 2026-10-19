<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CalendarEvent;
use App\Models\CulturalClaim;
use App\Models\CulturalClaimSource;
use App\Models\EventOccurrence;
use App\Models\EventSource;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\SchoolTradition;
use App\Models\SourceReview;
use App\Models\TraditionVersion;
use App\Models\User;
use App\Services\ProvenanceVerificationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed demo data for local and testing environments only.
     * Refuses execution in production environment.
     */
    public function run(): void
    {
        // 1. Strict guard: NEVER run demo seeds in production
        if (app()->isProduction()) {
            throw new \RuntimeException('DatabaseSeeder (demo data) cannot be executed in production environment.');
        }

        // 2. Resolve credentials from environment or generate cryptographic random passwords
        $isTesting = app()->environment('testing');

        $adminPassword = env('DEMO_ADMIN_PASSWORD') ?: ($isTesting ? 'TestAdminSecret2026!' : Str::password(24));
        $curatorPassword = env('DEMO_CURATOR_PASSWORD') ?: ($isTesting ? 'TestCuratorSecret2026!' : Str::password(24));
        $teacherPassword = env('DEMO_TEACHER_PASSWORD') ?: ($isTesting ? 'TestTeacherSecret2026!' : Str::password(24));
        $directorPassword = env('DEMO_DIRECTOR_PASSWORD') ?: ($isTesting ? 'TestDirectorSecret2026!' : Str::password(24));
        $multiPassword = env('DEMO_MULTI_PASSWORD') ?: ($isTesting ? 'TestMultiSecret2026!' : Str::password(24));
        $blockedPassword = env('DEMO_BLOCKED_PASSWORD') ?: ($isTesting ? 'TestBlockedSecret2026!' : 'TestBlockedSecret2026!');

        if (! $isTesting && ! env('DEMO_ADMIN_PASSWORD')) {
            $this->command?->warn("Generated secure DEMO_ADMIN_PASSWORD for admin@school-events.ru: {$adminPassword}");
            $this->command?->warn("Generated secure DEMO_TEACHER_PASSWORD for teacher@school130.ru: {$teacherPassword}");
        }

        // 3. Create Demo Schools
        $school1 = School::create([
            'name' => 'МАОУ Лицей № 130 г. Екатеринбург',
            'short_name' => 'Лицей № 130',
            'region_code' => '66',
            'city' => 'Екатеринбург',
            'inn' => '6660012345',
            'settings' => [
                'theme' => 'emerald',
                'brand_title' => 'Лицей 130',
            ],
        ]);

        $school2 = School::create([
            'name' => 'МАОУ Гимназия № 9 г. Екатеринбург',
            'short_name' => 'Гимназия № 9',
            'region_code' => '66',
            'city' => 'Екатеринбург',
            'inn' => '6660067890',
            'settings' => [
                'theme' => 'sapphire',
                'brand_title' => 'Гимназия 9',
            ],
        ]);

        // 4. System Administrator (no school membership)
        $admin = User::create([
            'name' => 'Системный Администратор',
            'email' => 'admin@school-events.ru',
            'password' => Hash::make($adminPassword),
            'system_role' => 'system_admin',
            'curator_scope' => null,
            'curated_region_codes' => [],
            'is_active' => true,
        ]);

        // 5. Catalog Curator (regional curator for Sverdlovsk oblast)
        $curator = User::create([
            'name' => 'Куратор Каталога Событий',
            'email' => 'curator@school-events.ru',
            'password' => Hash::make($curatorPassword),
            'system_role' => 'catalog_curator',
            'curator_scope' => 'regional',
            'curated_region_codes' => ['66'],
            'is_active' => true,
        ]);

        // 6. Teacher in School 1
        $teacher1 = User::create([
            'name' => 'Елена Сергеевна Смирнова',
            'email' => 'teacher@school130.ru',
            'password' => Hash::make($teacherPassword),
            'system_role' => 'none',
            'is_active' => true,
        ]);

        SchoolMembership::create([
            'school_id' => $school1->id,
            'user_id' => $teacher1->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);

        // 7. School Admin in School 1
        $director1 = User::create([
            'name' => 'Ирина Александровна Ковалева',
            'email' => 'director@school130.ru',
            'password' => Hash::make($directorPassword),
            'system_role' => 'none',
            'is_active' => true,
        ]);

        SchoolMembership::create([
            'school_id' => $school1->id,
            'user_id' => $director1->id,
            'role' => 'school_admin',
            'is_default' => true,
        ]);

        // 8. Multi-school teacher (School 1 and School 2)
        $multiTeacher = User::create([
            'name' => 'Михаил Юрьевич Петров',
            'email' => 'multi@schools.ru',
            'password' => Hash::make($multiPassword),
            'system_role' => 'none',
            'is_active' => true,
        ]);

        SchoolMembership::create([
            'school_id' => $school1->id,
            'user_id' => $multiTeacher->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);

        SchoolMembership::create([
            'school_id' => $school2->id,
            'user_id' => $multiTeacher->id,
            'role' => 'teacher',
            'is_default' => false,
        ]);

        // 9. Inactive user (for testing account deactivation)
        User::create([
            'name' => 'Заблокированный Пользователь',
            'email' => 'blocked@school130.ru',
            'password' => Hash::make($blockedPassword),
            'system_role' => 'none',
            'is_active' => false,
        ]);

        // 10. Demo Calendar Events & Cultural Provenance
        $provenanceService = app(ProvenanceVerificationService::class);

        // 10.1 Federal Event 1: День народного единства
        $fedEvent1 = CalendarEvent::create([
            'title' => 'День народного единства',
            'event_type' => 'federal',
            'date_type' => 'fixed_annual',
            'fixed_month' => 11,
            'fixed_day' => 4,
            'description' => 'Государственный праздник Российской Федерации, отмечаемый ежегодно 4 ноября в память об освобождении Москвы от интервентов в 1612 году.',
            'target_age_groups' => ['primary_1_4', 'basic_5_9', 'senior_10_11'],
            'recommended_value_directions' => ['historical_memory', 'civic_responsibility'],
            'status' => 'approved',
        ]);

        EventOccurrence::create([
            'calendar_event_id' => $fedEvent1->id,
            'academic_year' => '2026-2027',
            'start_date' => '2026-11-04',
            'end_date' => '2026-11-04',
        ]);

        $fedSource1 = EventSource::create([
            'calendar_event_id' => $fedEvent1->id,
            'source_type' => 'normative_act',
            'title' => 'Федеральный закон от 29.12.2004 № 201-ФЗ «О внесении изменений в статью 112 Трудового кодекса РФ»',
            'url' => 'http://pravo.gov.ru/proxy/ips/?docbody=&nd=102090226',
            'verified_by_user_id' => $admin->id,
            'verification_date' => now(),
        ]);

        SourceReview::create([
            'event_source_id' => $fedSource1->id,
            'reviewer_user_id' => $admin->id,
            'decision' => 'approved',
            'comments' => 'Официальный текст федерального закона проверен по государственной эталонной базе правовых актов Pravo.gov.ru.',
            'reviewed_at' => now(),
        ]);

        // Claim 1 (Normative): Confirmed by the normative act
        $fedClaim1Normative = CulturalClaim::create([
            'calendar_event_id' => $fedEvent1->id,
            'claim_text' => '4 ноября — официальный нерабочий праздничный день (День народного единства) в Российской Федерации.',
            'culture_name' => 'Общероссийская гражданская культура',
            'provenance_notes' => 'Установлен федеральным законом в качестве праздничного дня в РФ.',
        ]);

        $provenanceService->verifyClaim(
            claim: $fedClaim1Normative,
            source: $fedSource1,
            citationLocator: 'Статья 1, абзац 3',
            verifier: $admin,
            evidenceExcerpt: '«Статья 112... 4 ноября - День народного единства;»',
            academicYear: '2026-2027',
            notes: 'Верифицировано через Provenance Gate на основании официального текста ФЗ № 201-ФЗ.'
        );

        // Claim 2 (Historical): Events of 1612 (Unverified: cannot be verified by modern labor code law)
        $fedClaim1Historical = CulturalClaim::create([
            'calendar_event_id' => $fedEvent1->id,
            'claim_text' => 'События 1612 года: освобождение Москвы народным ополчением под предводительством Кузьмы Минина и Дмитрия Пожарского.',
            'culture_name' => 'Общероссийская гражданская культура',
            'provenance_notes' => 'Исторический контекст праздника, связанный с событиями Смутного времени 1612 года.',
        ]);

        CulturalClaimSource::create([
            'cultural_claim_id' => $fedClaim1Historical->id,
            'event_source_id' => $fedSource1->id,
            'calendar_event_id' => $fedEvent1->id,
            'citation_locator' => 'Преамбула пояснительной записки',
            'verification_status' => 'unverified',
            'verified_by_user_id' => null,
            'verified_at' => null,
            'applicable_academic_year' => '2026-2027',
            'notes' => 'Исторические сведения о событиях 1612 года: статус unverified, требуют подтверждения по академическому историческому первоисточнику.',
        ]);

        // 10.2 Federal Event 2: День российской науки
        $fedEvent2 = CalendarEvent::create([
            'title' => 'День российской науки',
            'event_type' => 'federal',
            'date_type' => 'fixed_annual',
            'fixed_month' => 2,
            'fixed_day' => 8,
            'description' => 'Профессиональный праздник академического и научного сообщества РФ, учрежденный к дате основания Академии наук Петром I.',
            'target_age_groups' => ['basic_5_9', 'senior_10_11'],
            'recommended_value_directions' => ['labor_and_creation', 'historical_memory'],
            'status' => 'approved',
        ]);

        EventOccurrence::create([
            'calendar_event_id' => $fedEvent2->id,
            'academic_year' => '2026-2027',
            'start_date' => '2027-02-08',
            'end_date' => '2027-02-08',
        ]);

        $fedSource2 = EventSource::create([
            'calendar_event_id' => $fedEvent2->id,
            'source_type' => 'normative_act',
            'title' => 'Указ Президента Российской Федерации от 07.06.1999 № 717 «Об установлении Дня российской науки»',
            'url' => 'http://pravo.gov.ru/proxy/ips/?docbody=&nd=102059733',
            'verified_by_user_id' => $admin->id,
            'verification_date' => now(),
        ]);

        SourceReview::create([
            'event_source_id' => $fedSource2->id,
            'reviewer_user_id' => $admin->id,
            'decision' => 'approved',
            'comments' => 'Указ Президента РФ верифицирован по государственной базе правовых актов Pravo.gov.ru.',
            'reviewed_at' => now(),
        ]);

        // Claim 1 (Normative): Confirmed by presidential decree
        $fedClaim2Normative = CulturalClaim::create([
            'calendar_event_id' => $fedEvent2->id,
            'claim_text' => '8 февраля — официальный профессиональный праздник (День российской науки) в Российской Федерации.',
            'culture_name' => 'Научное наследие России',
            'provenance_notes' => 'Установлен указом Президента РФ в ознаменование вклада науки в развитие государства.',
        ]);

        $provenanceService->verifyClaim(
            claim: $fedClaim2Normative,
            source: $fedSource2,
            citationLocator: 'Пункт 1 Указа Президента РФ № 717',
            verifier: $admin,
            evidenceExcerpt: '«1. Установить День российской науки и праздновать его 8 февраля.»',
            academicYear: '2026-2027',
            notes: 'Верифицировано через Provenance Gate на основании государственного указа.'
        );

        // Claim 2 (Historical): Foundation of Academy in 1724 (Unverified)
        $fedClaim2Historical = CulturalClaim::create([
            'calendar_event_id' => $fedEvent2->id,
            'claim_text' => '8 февраля (28 января по ст. стилю) 1724 года по распоряжению Петра I был издан указ Правительствующего Сената об учреждении Академии наук и художеств.',
            'culture_name' => 'Научное наследие России',
            'provenance_notes' => 'Историческая дата основания Академии наук в 1724 году.',
        ]);

        CulturalClaimSource::create([
            'cultural_claim_id' => $fedClaim2Historical->id,
            'event_source_id' => $fedSource2->id,
            'calendar_event_id' => $fedEvent2->id,
            'citation_locator' => 'Преамбула Указа № 717 (отсылочная норма)',
            'verification_status' => 'unverified',
            'verified_by_user_id' => null,
            'verified_at' => null,
            'applicable_academic_year' => '2026-2027',
            'notes' => 'Исторические детали основания Академии наук в 1724 году: статус unverified, требуют архивного подтверждения по ПСЗ РИ.',
        ]);

        // 10.3 Regional Event: День народов Среднего Урала (Unverified Ethnographic Demonstration Data)
        $regEvent = CalendarEvent::create([
            'title' => 'День народов Среднего Урала',
            'event_type' => 'regional',
            'region_code' => '66',
            'date_type' => 'fixed_annual',
            'fixed_month' => 9,
            'fixed_day' => 5,
            'description' => 'Региональный праздник межнационального согласия и культурного многообразия Свердловской области.',
            'target_age_groups' => ['primary_1_4', 'basic_5_9', 'senior_10_11'],
            'recommended_value_directions' => ['cultural_diversity', 'mutual_aid'],
            'status' => 'approved',
        ]);

        EventOccurrence::create([
            'calendar_event_id' => $regEvent->id,
            'academic_year' => '2026-2027',
            'start_date' => '2026-09-05',
            'end_date' => '2026-09-05',
        ]);

        $regSource = EventSource::create([
            'calendar_event_id' => $regEvent->id,
            'source_type' => 'scientific_publication',
            'title' => 'Демонстрационные этнографические материалы Среднего Урала (требуется архивная экспертиза)',
            'author' => 'Институт истории и археологии УрО РАН (демонстрационная фикстура)',
            'doi_or_isbn' => null,
            'verified_by_user_id' => null,
            'verification_date' => null,
        ]);

        $regClaim = CulturalClaim::create([
            'calendar_event_id' => $regEvent->id,
            'claim_text' => 'Традиции праздника объединяют национальности и народности, исторически проживающие на территории Среднего Урала.',
            'culture_name' => 'Традиционная культура народов Среднего Урала',
            'provenance_notes' => 'Демонстрационные краеведческие материалы (ожидают полевой этнографической экспертизы).',
        ]);

        // Explicitly unverified demonstration evidence record
        CulturalClaimSource::create([
            'cultural_claim_id' => $regClaim->id,
            'event_source_id' => $regSource->id,
            'calendar_event_id' => $regEvent->id,
            'citation_locator' => 'Демонстрационный локатор (ожидает архивной сверки)',
            'verification_status' => 'unverified',
            'verified_by_user_id' => null,
            'verified_at' => null,
            'applicable_academic_year' => '2026-2027',
            'notes' => 'Демонстрационные данные: этнографические сведения не проходили полевую экспертизу и ожидают проверки куратором региона 66.',
        ]);

        // 11. Demo School Traditions & Annual Versions
        // 11.1 School 1 (Лицей № 130) Tradition
        $tradition1 = SchoolTradition::create([
            'school_id' => $school1->id,
            'title' => 'Посвящение в лицеисты',
            'description' => 'Ежегодное торжественное лицейское событие в день основания Царскосельского лицея 19 октября.',
            'established_year' => 1991,
            'created_by_user_id' => $teacher1->id,
        ]);

        TraditionVersion::create([
            'tradition_id' => $tradition1->id,
            'academic_year' => '2025-2026',
            'parameters_snapshot' => [
                'scale' => 'class_level',
                'target_grades' => ['primary_1_4', 'basic_5_9'],
                'participants_count' => 140,
                'duration_minutes' => 60,
                'theme' => 'Лицейское братство: традиция поколений',
            ],
            'notes' => '34-я церемония посвящения в лицеисты.',
            'created_by_user_id' => $teacher1->id,
        ]);

        TraditionVersion::create([
            'tradition_id' => $tradition1->id,
            'academic_year' => '2026-2027',
            'parameters_snapshot' => [
                'scale' => 'all_school',
                'target_grades' => ['primary_1_4', 'basic_5_9', 'senior_10_11'],
                'participants_count' => 200,
                'duration_minutes' => 75,
                'theme' => 'Юбилейный год: Лицей 130 устремленный в будущее',
            ],
            'notes' => '35-я юбилейная церемония с участием выпускников.',
            'created_by_user_id' => $director1->id,
        ]);

        // 11.2 School 2 (Гимназия № 9) Tradition
        $tradition2 = SchoolTradition::create([
            'school_id' => $school2->id,
            'title' => 'Гимназический весенний бал',
            'description' => 'Традиционный классический бал старшеклассников гимназии.',
            'established_year' => 1995,
            'created_by_user_id' => $multiTeacher->id,
        ]);

        TraditionVersion::create([
            'tradition_id' => $tradition2->id,
            'academic_year' => '2025-2026',
            'parameters_snapshot' => [
                'scale' => 'class_level',
                'target_grades' => ['senior_10_11'],
                'participants_count' => 80,
                'duration_minutes' => 120,
            ],
            'notes' => 'Весенний бал в историческом зале.',
            'created_by_user_id' => $multiTeacher->id,
        ]);

        TraditionVersion::create([
            'tradition_id' => $tradition2->id,
            'academic_year' => '2026-2027',
            'parameters_snapshot' => [
                'scale' => 'class_level',
                'target_grades' => ['senior_10_11'],
                'participants_count' => 95,
                'duration_minutes' => 120,
            ],
            'notes' => 'Бал в честь года классического образования.',
            'created_by_user_id' => $multiTeacher->id,
        ]);
    }
}
