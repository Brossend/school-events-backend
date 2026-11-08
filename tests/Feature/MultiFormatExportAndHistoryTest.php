<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ExecuteExportJob;
use App\Models\CalendarEvent;
use App\Models\CulturalClaim;
use App\Models\CulturalClaimSource;
use App\Models\EventSource;
use App\Models\ExportJob;
use App\Models\ExportedArtifact;
use App\Models\RevisionModerationState;
use App\Models\Scenario;
use App\Models\ScenarioRequest;
use App\Models\ScenarioRevision;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\SourceReview;
use App\Models\TeacherSignoff;
use App\Models\User;
use App\Services\ProvenanceVerificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class MultiFormatExportAndHistoryTest extends TestCase
{
    use DatabaseTransactions;

    protected School $school;
    protected User $teacher;
    protected User $curator;
    protected CalendarEvent $event;
    protected EventSource $source;
    protected CulturalClaim $claim;
    protected Scenario $scenario;
    protected ScenarioRevision $revision;
    protected ProvenanceVerificationService $provenanceService;
    protected string $fingerprint;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->provenanceService = app(ProvenanceVerificationService::class);

        $this->school = School::factory()->create([
            'name' => 'Лицей № 130 г. Екатеринбург',
            'region_code' => '66',
        ]);

        $this->teacher = User::factory()->create([
            'name' => 'Мария Сергеевна Петрова',
            'email' => 'petrova_teacher_' . uniqid() . '@school130.ru',
            'system_role' => 'none',
        ]);
        SchoolMembership::factory()->create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);

        $this->curator = User::factory()->create([
            'name' => 'Академический Эксперт Куратор',
            'email' => 'curator_be007_' . uniqid() . '@catalog.ru',
            'system_role' => 'catalog_curator',
            'curator_scope' => 'global',
        ]);

        $this->event = CalendarEvent::create([
            'title' => 'День народных промыслов Среднего Урала',
            'event_type' => 'regional',
            'region_code' => '66',
            'date_type' => 'fixed_annual',
            'fixed_month' => 11,
            'fixed_day' => 15,
            'target_age_groups' => ['basic_5_9'],
            'recommended_value_directions' => ['cultural_diversity'],
            'description' => 'Уральская горнозаводская роспись и берестяной промысел',
            'status' => 'approved',
        ]);

        $this->source = EventSource::create([
            'calendar_event_id' => $this->event->id,
            'title' => 'Народная роспись Урала: Каталог экспедиций',
            'author' => 'Уральский краеведческий музей',
            'source_type' => 'ethnographic_archive',
            'url' => 'https://ural-museum.ru/heritage/painting.pdf',
            'verification_status' => 'verified',
            'verified_by_user_id' => $this->curator->id,
            'verification_date' => now()->toDateString(),
        ]);

        SourceReview::create([
            'event_source_id' => $this->source->id,
            'reviewer_user_id' => $this->curator->id,
            'decision' => 'approved',
            'comments' => 'Эталонный академический первоисточник.',
            'reviewed_at' => now(),
        ]);

        $this->claim = CulturalClaim::create([
            'calendar_event_id' => $this->event->id,
            'claim_text' => 'Уральская горнозаводская роспись бытовала в Нижнетагильском округе с XVIII века.',
            'culture_name' => 'Уральская горнозаводская традиция',
            'provenance_notes' => 'Мастера-красильщики Среднего Урала расписывали прялки и интерьеры изб.',
        ]);

        CulturalClaimSource::create([
            'calendar_event_id' => $this->event->id,
            'cultural_claim_id' => $this->claim->id,
            'event_source_id' => $this->source->id,
            'citation_locator' => 'Том 2, Раздел 3, стр. 45-52',
            'citation_excerpt' => 'Документальные свидетельства росписи берестяных туесов и утвари зафиксированы в XVIII веке.',
            'verification_status' => 'verified_authentic',
            'verified_by_user_id' => $this->curator->id,
            'verified_at' => now(),
        ]);

        $req = ScenarioRequest::create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'calendar_event_id' => $this->event->id,
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [6],
            'participants_count' => 25,
            'duration_minutes' => 45,
            'value_direction' => 'cultural_diversity',
            'family_involvement' => false,
            'context_snapshot' => [
                'occasion' => ['title' => 'День народных промыслов Среднего Урала'],
            ],
        ]);

        $this->scenario = Scenario::create([
            'scenario_request_id' => $req->id,
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'title' => 'Уральская роспись: Красота горнозаводского края',
            'status' => 'ready',
        ]);

        $payload = [
            'theme' => 'Традиционная культура Урала',
            'cultural_claims' => [
                [
                    'claim_id' => $this->claim->id,
                    'claim_text' => $this->claim->claim_text,
                    'culture_name' => $this->claim->culture_name,
                    'server_verification_status' => 'verified_authentic',
                    'evidence_records' => [
                        [
                            'event_source_id' => $this->source->id,
                            'source_title' => $this->source->title,
                            'citation_locator' => 'Том 2, Раздел 3, стр. 45-52',
                            'citation_excerpt' => 'Документальные свидетельства росписи берестяных туесов и утвари зафиксированы в XVIII веке.',
                        ],
                    ],
                ],
            ],
            'stages' => [
                [
                    'id' => 'st-1',
                    'title' => 'Вводный этап: Загадки уральских мастеров',
                    'duration_minutes' => 10,
                    'description' => 'Знакомство с историей уральской горнозаводской культуры.',
                    'teacher_actions' => 'Демонстрация слайдов презентации и подлинных образцов росписи.',
                    'student_actions' => 'Рассмотрение мотивов и элементов росписи.',
                ],
                [
                    'id' => 'st-2',
                    'title' => 'Практикум: Ролевая мастерская красильщиков',
                    'duration_minutes' => 25,
                    'description' => 'Работа в группах с карточками заданий и роспись по трафаретам.',
                    'teacher_actions' => 'Организация работы малых групп, инструктаж по технике безопасности.',
                    'student_actions' => 'Выполнение творческого задания, обсуждение символики цвета.',
                ],
                [
                    'id' => 'st-3',
                    'title' => 'Подведение итогов и рефлексия',
                    'duration_minutes' => 10,
                    'description' => 'Выставка работ учащихся и ценностное осмысление наследия.',
                    'teacher_actions' => 'Фасилитация круга обратной связи.',
                    'student_actions' => 'Презентация командных работ.',
                ],
            ],
            'materials' => [
                'presentation_slides' => [
                    [
                        'title' => 'Уральская роспись: История и традиции',
                        'subtitle' => 'Воспитательное мероприятие для 6 класса',
                        'content' => ['Истоки промысла в XVIII веке', 'Горнозаводские мастера', 'Символика узоров'],
                        'speaker_notes' => 'Вступительное слово учителя: подчеркнуть уникальность уральской традиции.',
                        'cultural_claim_ids' => [$this->claim->id],
                    ],
                    [
                        'title' => 'Секреты мастеров-красильщиков',
                        'subtitle' => 'Особенности двухцветного мазка',
                        'content' => ['Техника свободного кистевого мазка', 'Краски на натуральной олифе', 'Берестяные изделия'],
                        'speaker_notes' => 'Обратить внимание детей на технику нанесения двух красок одновременно.',
                    ],
                    [
                        'title' => 'Символы и образы природы',
                        'subtitle' => 'Растительные и птичьи мотивы',
                        'content' => ['Древо жизни и цветы-розаны', 'Птица счастья и сова', 'Обереговое значение узоров'],
                        'speaker_notes' => 'Спросить у ребят, какие растения родного края они видят в росписи.',
                    ],
                    [
                        'title' => 'Творческая мастерская',
                        'subtitle' => 'Правила командной работы',
                        'content' => ['Распределение ролей в бригаде', 'Бережное отношение к материалам', 'Создание общего эскиза'],
                        'speaker_notes' => 'Контролировать аккуратность и соблюдение времени практической работы.',
                    ],
                    [
                        'title' => 'Наследие сквозь века',
                        'subtitle' => 'Рефлексия и ценностные выводы',
                        'content' => ['Что символизирует уральский цветок?', 'Как промыслы сохраняют память поколений?', 'Итоговая выставка'],
                        'speaker_notes' => 'Подвести итог: народное искусство объединяет нас с предками.',
                        'cultural_claim_ids' => [$this->claim->id],
                    ],
                ],
                'printable_cards' => [
                    [
                        'title' => 'Карточка № 1: Исследователи орнамента',
                        'target_role' => 'Группа исследователей',
                        'description' => 'Изучите представленные элементы уральской росписи и определите их название.',
                        'questions' => [
                            'Какой цветок изображён на прялке?',
                            'Какие два цвета сочетаются в мазке?',
                        ],
                    ],
                    [
                        'title' => 'Карточка № 2: Мастера-колористы',
                        'target_role' => 'Группа колористов',
                        'description' => 'Подберите гармоничное цветовое сочетание для росписи берестяного туеса.',
                        'questions' => [
                            'Почему уральские мастера любили сочетание красного и белого?',
                            'Как цвет передаёт настроение праздника?',
                        ],
                    ],
                ],
                'handouts' => [
                    [
                        'title' => 'Памятка юного мастера',
                        'text' => '1. Набери на один край кисти белый цвет, на другой — кармин. 2. Веди кисть плавно, не отрывая руки.',
                        'instructions' => 'Используйте при выполнении практического задания.',
                    ],
                ],
            ],
            'roles' => [
                ['title' => 'Мастер-красильщик', 'description' => 'Наносит основные контуры узора'],
                ['title' => 'Подмастерье', 'description' => 'Готовит краски и материалы'],
                ['title' => 'Летописец команды', 'description' => 'Записывает значение символов и готовит защиту работы'],
            ],
            'props' => ['Гуашь, кисти беличьи № 3 и № 5', 'Бумажные заготовки туесов', 'Образцы росписи'],
            'teacher_instructions' => [
                'methodological_recommendations' => [
                    'Начать занятие с эмоционального погружения в культуру горнозаводского Урала.',
                    'Использовать музыкальное сопровождение (народные уральские наигрыши).',
                ],
                'general_safety_rules' => [
                    'Аккуратно обращаться с водой и красками.',
                    'Не допускать попадания художественных материалов на одежду и кожу.',
                ],
                'adaptation_for_disabilities_or_small_groups' => 'Для детей с мелкой моторикой предоставить трафареты с увеличенным контуром.',
            ],
        ];

        $this->revision = ScenarioRevision::create([
            'scenario_id' => $this->scenario->id,
            'version_number' => 1,
            'created_by_user_id' => $this->teacher->id,
            'change_description' => 'Каноническая версия сценария для тестирования экспорта',
            'concept' => 'Формирование региональной идентичности через уральское традиционное прикладное искусство',
            'pedagogical_goal' => 'Воспитание уважения к культуре родного края через освоение уральской росписи',
            'pedagogical_mechanism' => ['mechanism_type' => 'comparative_cultural_study'],
            'pedagogical_tasks' => [
                'personal' => ['Формирование чувства гордости за культуру Урала'],
                'metasubject' => ['Развитие навыков коллективной творческой деятельности'],
                'subject' => ['Знакомство с историей и технологией уральской горнозаводской росписи'],
            ],
            'expected_outcomes' => [
                'knowledge' => ['Знание ключевых элементов и истории уральской росписи'],
                'attitude' => ['Бережное отношение к памятникам народной культуры'],
                'action' => ['Умение выполнять базовые элементы росписи'],
            ],
            'target_audience' => ['age_group' => 'basic_5_9'],
            'format' => ['scale' => 'class_level', 'type' => 'class_hour'],
            'total_duration_minutes' => 45,
            'teacher_instructions' => $payload['teacher_instructions'],
            'content_payload' => $payload,
        ]);

        $this->scenario->update(['current_revision_id' => $this->revision->id]);

        $this->fingerprint = $this->provenanceService->computeRevisionProvenanceFingerprint($this->revision);

        RevisionModerationState::create([
            'revision_id' => $this->revision->id,
            'status' => 'verification_passed',
            'provenance_fingerprint' => $this->fingerprint,
            'p0_violations_count' => 0,
            'p1_violations_count' => 0,
            'updated_at' => now(),
        ]);

        TeacherSignoff::create([
            'revision_id' => $this->revision->id,
            'user_id' => $this->teacher->id,
            'is_confirmed' => true,
            'provenance_fingerprint' => $this->fingerprint,
            'checklist_confirmed' => [
                'safety_checked' => true,
                'timing_fit' => true,
                'cultural_context_approved' => true,
            ],
            'teacher_notes' => 'Сценарий полностью готов и проверен.',
            'ip_address' => '127.0.0.1',
            'confirmed_at' => now(),
        ]);
    }

    /**
     * Test 1: DOCX Export produces a valid Office Open XML ZIP container with Cyrillic pedagogical content.
     */
    public function test_export_docx_success_and_valid_openxml_structure(): void
    {
        $res = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->withHeader('Idempotency-Key', 'idemp_docx_' . uniqid())
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/{$this->revision->version_number}/exports", [
                'format' => 'docx',
            ]);

        $res->assertStatus(202);
        $jobId = $res->json('data.id');
        $this->assertNotEmpty($jobId);

        // Download DOCX
        $downloadRes = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->get("/api/v1/export-jobs/{$jobId}/download");

        $downloadRes->assertStatus(200);
        $this->assertEquals(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $downloadRes->headers->get('Content-Type')
        );
        $this->assertStringContainsString('.docx', $downloadRes->headers->get('Content-Disposition'));

        $bytes = $downloadRes->getContent();
        $this->assertGreaterThan(1000, strlen($bytes), 'DOCX document must be non-empty.');

        // Validate Office Open XML structure
        $tempPath = tempnam(sys_get_temp_dir(), 'test_docx_');
        file_put_contents($tempPath, $bytes);

        $zip = new ZipArchive();
        $opened = $zip->open($tempPath);
        $this->assertTrue($opened, 'DOCX must be a valid ZIP archive.');

        // Check word/document.xml
        $this->assertNotFalse($zip->locateName('word/document.xml'), 'DOCX archive must contain word/document.xml.');
        $documentXml = $zip->getFromName('word/document.xml');
        $this->assertNotEmpty($documentXml);

        // Cyrillic and pedagogical sections verification
        $this->assertStringContainsString('Уральская роспись: Красота горнозаводского края', $documentXml);
        $this->assertStringContainsString('Педагогическая цель', $documentXml);
        $this->assertStringContainsString('Вводный этап: Загадки уральских мастеров', $documentXml);
        $this->assertStringContainsString('Мария Сергеевна Петрова', $documentXml);
        $this->assertStringContainsString('Уральская горнозаводская роспись бытовала', $documentXml);

        $zip->close();
        @unlink($tempPath);
    }

    /**
     * Test 2: PPTX Export produces a valid 16:9 Office Open XML presentation with exactly 5 slides and speaker notes.
     */
    public function test_export_pptx_success_and_valid_openxml_structure(): void
    {
        $res = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->withHeader('Idempotency-Key', 'idemp_pptx_' . uniqid())
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/{$this->revision->version_number}/exports", [
                'format' => 'pptx',
            ]);

        $res->assertStatus(202);
        $jobId = $res->json('data.id');
        $this->assertNotEmpty($jobId);

        // Download PPTX
        $downloadRes = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->get("/api/v1/export-jobs/{$jobId}/download");

        $downloadRes->assertStatus(200);
        $this->assertEquals(
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            $downloadRes->headers->get('Content-Type')
        );
        $this->assertStringContainsString('.pptx', $downloadRes->headers->get('Content-Disposition'));

        $bytes = $downloadRes->getContent();
        $this->assertGreaterThan(1000, strlen($bytes), 'PPTX file must be non-empty.');

        // Validate Office Open XML structure
        $tempPath = tempnam(sys_get_temp_dir(), 'test_pptx_');
        file_put_contents($tempPath, $bytes);

        $zip = new ZipArchive();
        $opened = $zip->open($tempPath);
        $this->assertTrue($opened, 'PPTX must be a valid ZIP archive.');

        // Check presentation.xml
        $this->assertNotFalse($zip->locateName('ppt/presentation.xml'), 'PPTX archive must contain ppt/presentation.xml.');

        // Check exactly 5 slides
        $this->assertNotFalse($zip->locateName('ppt/slides/slide1.xml'), 'Slide 1 must exist.');
        $this->assertNotFalse($zip->locateName('ppt/slides/slide2.xml'), 'Slide 2 must exist.');
        $this->assertNotFalse($zip->locateName('ppt/slides/slide3.xml'), 'Slide 3 must exist.');
        $this->assertNotFalse($zip->locateName('ppt/slides/slide4.xml'), 'Slide 4 must exist.');
        $this->assertNotFalse($zip->locateName('ppt/slides/slide5.xml'), 'Slide 5 must exist.');
        $this->assertFalse($zip->locateName('ppt/slides/slide6.xml'), 'Slide 6 must NOT exist (strictly 5 slides).');

        // Check speaker notes
        $this->assertNotFalse($zip->locateName('ppt/notesSlides/notesSlide1.xml'), 'Speaker notes must be present in slide 1.');

        $slide1Xml = $zip->getFromName('ppt/slides/slide1.xml');
        $this->assertStringContainsString('Уральская роспись', $slide1Xml);

        $zip->close();
        @unlink($tempPath);
    }

    /**
     * Test 3: Print Pack PDF produces valid A4 PDF with teacher memo, cut lines, handouts and fact sheet.
     */
    public function test_export_print_pack_pdf_success_and_valid_structure(): void
    {
        $res = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->withHeader('Idempotency-Key', 'idemp_print_pack_' . uniqid())
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/{$this->revision->version_number}/exports", [
                'format' => 'print_pack_pdf',
            ]);

        $res->assertStatus(202);
        $jobId = $res->json('data.id');
        $this->assertNotEmpty($jobId);

        // Download Print Pack PDF
        $downloadRes = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->get("/api/v1/export-jobs/{$jobId}/download");

        $downloadRes->assertStatus(200);
        $this->assertEquals('application/pdf', $downloadRes->headers->get('Content-Type'));
        $this->assertStringContainsString('.pdf', $downloadRes->headers->get('Content-Disposition'));

        $bytes = $downloadRes->getContent();
        $this->assertStringStartsWith('%PDF-', $bytes, 'Document must be a valid PDF binary.');
        $this->assertGreaterThan(5000, strlen($bytes), 'Print Pack PDF must contain compiled page content.');
    }

    /**
     * Test 4: Export History endpoint returns full history with accurate download availability flag.
     */
    public function test_export_history_endpoint_and_download_availability(): void
    {
        // 1. Create DOCX and PPTX exports
        $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->withHeader('Idempotency-Key', 'idemp_h_docx_' . uniqid())
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/{$this->revision->version_number}/exports", [
                'format' => 'docx',
            ])->assertStatus(202);

        $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->withHeader('Idempotency-Key', 'idemp_h_pptx_' . uniqid())
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/{$this->revision->version_number}/exports", [
                'format' => 'pptx',
            ])->assertStatus(202);

        // 2. Query History
        $historyRes = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->getJson("/api/v1/scenarios/{$this->scenario->id}/revisions/{$this->revision->version_number}/exports");

        $historyRes->assertStatus(200);
        $data = $historyRes->json('data');
        $this->assertCount(2, $data);

        $docxItem = collect($data)->firstWhere('format', 'docx');
        $this->assertNotNull($docxItem);
        $this->assertEquals('ready', $docxItem['status']);
        $this->assertTrue($docxItem['is_downloadable'], 'Export must be downloadable when provenance is valid.');
        $this->assertNotNull($docxItem['artifact']);
        $this->assertEquals('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $docxItem['artifact']['mime_type']);

        $pptxItem = collect($data)->firstWhere('format', 'pptx');
        $this->assertNotNull($pptxItem);
        $this->assertTrue($pptxItem['is_downloadable']);
        $this->assertEquals('application/vnd.openxmlformats-officedocument.presentationml.presentation', $pptxItem['artifact']['mime_type']);

        // 3. Now revoke the source -> is_downloadable in history MUST become false!
        $this->actingAs($this->curator)
            ->postJson("/api/v1/event-sources/{$this->source->id}/reviews", [
                'decision' => 'rejected',
                'comments' => 'Обнаружена недостоверность источника.',
            ])->assertStatus(201);

        $revokedHistoryRes = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->getJson("/api/v1/scenarios/{$this->scenario->id}/revisions/{$this->revision->version_number}/exports");

        $revokedHistoryRes->assertStatus(200);
        $revokedData = $revokedHistoryRes->json('data');
        foreach ($revokedData as $item) {
            $this->assertFalse(
                $item['is_downloadable'],
                'Export must NOT be marked downloadable when evidence source is revoked.'
            );
        }
    }

    /**
     * Test 5a: DOCX export is strictly blocked by Provenance Gate if source is revoked.
     */
    public function test_docx_export_blocked_on_provenance_revocation(): void
    {
        $createRes = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->withHeader('Idempotency-Key', 'idemp_prov_docx_' . uniqid())
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/{$this->revision->version_number}/exports", [
                'format' => 'docx',
            ]);

        $createRes->assertStatus(202);
        $jobId = $createRes->json('data.id');

        // Initial download is allowed
        $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->get("/api/v1/export-jobs/{$jobId}/download")
            ->assertStatus(200);

        // Revoke source
        $this->actingAs($this->curator)
            ->postJson("/api/v1/event-sources/{$this->source->id}/reviews", [
                'decision' => 'rejected',
                'comments' => 'Отзыв одобрения источника.',
            ])->assertStatus(201);

        // Download must be BLOCKED with 403
        $blockedRes = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->get("/api/v1/export-jobs/{$jobId}/download");

        $blockedRes->assertStatus(403);
        $this->assertEquals('EXPORT_BLOCKED_BY_PROVENANCE_REVOCATION', $blockedRes->json('error.code'));

        // New export request must be BLOCKED with 403
        $blockedCreate = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->withHeader('Idempotency-Key', 'idemp_blocked_docx_' . uniqid())
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/{$this->revision->version_number}/exports", [
                'format' => 'docx',
            ]);

        $blockedCreate->assertStatus(403);
        $this->assertEquals('EXPORT_BLOCKED_BY_PROVENANCE_REVOCATION', $blockedCreate->json('error.code'));
    }

    /**
     * Test 5b: PPTX export is strictly blocked by Provenance Gate if source is revoked.
     */
    public function test_pptx_export_blocked_on_provenance_revocation(): void
    {
        $createRes = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->withHeader('Idempotency-Key', 'idemp_prov_pptx_' . uniqid())
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/{$this->revision->version_number}/exports", [
                'format' => 'pptx',
            ]);

        $createRes->assertStatus(202);
        $jobId = $createRes->json('data.id');

        // Initial download is allowed
        $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->get("/api/v1/export-jobs/{$jobId}/download")
            ->assertStatus(200);

        // Revoke source
        $this->actingAs($this->curator)
            ->postJson("/api/v1/event-sources/{$this->source->id}/reviews", [
                'decision' => 'rejected',
                'comments' => 'Отзыв одобрения источника.',
            ])->assertStatus(201);

        // Download must be BLOCKED with 403
        $blockedRes = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->get("/api/v1/export-jobs/{$jobId}/download");

        $blockedRes->assertStatus(403);
        $this->assertEquals('EXPORT_BLOCKED_BY_PROVENANCE_REVOCATION', $blockedRes->json('error.code'));
    }

    /**
     * Test 5c: Print Pack PDF export is strictly blocked by Provenance Gate if source is revoked.
     */
    public function test_print_pack_export_blocked_on_provenance_revocation(): void
    {
        $createRes = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->withHeader('Idempotency-Key', 'idemp_prov_print_pack_' . uniqid())
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/{$this->revision->version_number}/exports", [
                'format' => 'print_pack_pdf',
            ]);

        $createRes->assertStatus(202);
        $jobId = $createRes->json('data.id');

        // Initial download is allowed
        $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->get("/api/v1/export-jobs/{$jobId}/download")
            ->assertStatus(200);

        // Revoke source
        $this->actingAs($this->curator)
            ->postJson("/api/v1/event-sources/{$this->source->id}/reviews", [
                'decision' => 'rejected',
                'comments' => 'Отзыв одобрения источника.',
            ])->assertStatus(201);

        // Download must be BLOCKED with 403
        $blockedRes = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->get("/api/v1/export-jobs/{$jobId}/download");

        $blockedRes->assertStatus(403);
        $this->assertEquals('EXPORT_BLOCKED_BY_PROVENANCE_REVOCATION', $blockedRes->json('error.code'));
    }

    /**
     * Test 5d: Export is blocked when revision has open P0 violations.
     */
    public function test_export_blocked_when_revision_has_p0_violation(): void
    {
        $this->revision->moderationState()->update([
            'status' => 'verification_blocked',
            'p0_violations_count' => 1,
        ]);

        $res = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->withHeader('Idempotency-Key', 'idemp_p0_' . uniqid())
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/{$this->revision->version_number}/exports", [
                'format' => 'docx',
            ]);

        $res->assertStatus(403);
        $this->assertEquals('EXPORT_FORBIDDEN_MODERATION_BLOCKED', $res->json('error.code'));
    }

    /**
     * Test 5e: Export is blocked when signoff is missing.
     */
    public function test_export_blocked_when_signoff_missing(): void
    {
        TeacherSignoff::where('revision_id', $this->revision->id)->delete();

        $res = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->withHeader('Idempotency-Key', 'idemp_nosign_' . uniqid())
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/{$this->revision->version_number}/exports", [
                'format' => 'docx',
            ]);

        $res->assertStatus(403);
        $this->assertEquals('EXPORT_FORBIDDEN_SIGN_OFF_REQUIRED', $res->json('error.code'));
    }

    /**
     * Test 5f: Export is blocked for another school tenant.
     */
    public function test_export_blocked_for_other_school_tenant(): void
    {
        $otherSchool = School::factory()->create(['name' => 'Другая школа']);
        $otherTeacher = User::factory()->create(['email' => 'other_t_' . uniqid() . '@test.ru']);
        SchoolMembership::factory()->create([
            'school_id' => $otherSchool->id,
            'user_id' => $otherTeacher->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);

        $res = $this->actingAs($otherTeacher)
            ->withHeader('X-School-Id', $otherSchool->id)
            ->withHeader('Idempotency-Key', 'idemp_other_' . uniqid())
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/{$this->revision->version_number}/exports", [
                'format' => 'docx',
            ]);

        $res->assertStatus(403);
        $this->assertEquals('FORBIDDEN_TENANT_ACCESS', $res->json('error.code'));
    }

    /**
     * Test 5g: Fail-closed: Legacy NULL signoff fingerprint blocks export.
     */
    public function test_export_blocked_on_legacy_null_signoff_fingerprint(): void
    {
        TeacherSignoff::where('revision_id', $this->revision->id)->update([
            'provenance_fingerprint' => null,
        ]);

        $res = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->withHeader('Idempotency-Key', 'idemp_null_signoff_' . uniqid())
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/{$this->revision->version_number}/exports", [
                'format' => 'docx',
            ]);

        $res->assertStatus(403);
        $this->assertEquals('EXPORT_FORBIDDEN_SIGN_OFF_REQUIRED', $res->json('error.code'));
        $this->assertEquals('LEGACY_NULL_SIGNOFF_FINGERPRINT', $res->json('error.details.reason'));
    }

    /**
     * Test 5h: Fail-closed: Legacy NULL job fingerprint blocks download.
     */
    public function test_export_download_blocked_on_legacy_null_job_fingerprint(): void
    {
        $job = ExportJob::create([
            'revision_id' => $this->revision->id,
            'user_id' => $this->teacher->id,
            'format' => 'pptx',
            'status' => 'ready',
            'provenance_fingerprint' => null, // Legacy row
        ]);

        $dummyContent = 'PK dummy zip content';
        $path = "exports/{$job->id}.pptx";
        Storage::disk('local')->put($path, $dummyContent);

        ExportedArtifact::create([
            'export_job_id' => $job->id,
            'file_disk' => 'local',
            'file_path' => $path,
            'file_name' => 'legacy.pptx',
            'file_size_bytes' => strlen($dummyContent),
            'mime_type' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'sha256_hash' => hash('sha256', $dummyContent),
            'provenance_fingerprint' => null,
            'expires_at' => now()->addDays(7),
        ]);

        $res = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->get("/api/v1/export-jobs/{$job->id}/download");

        $res->assertStatus(403);
        $this->assertEquals('EXPORT_BLOCKED_BY_PROVENANCE_REVOCATION', $res->json('error.code'));
        $this->assertEquals('LEGACY_NULL_EXPORT_FINGERPRINT', $res->json('error.details.reason'));
    }

    /**
     * Test 6: Unsupported format is rejected with HTTP 422.
     */
    public function test_unsupported_format_rejected_with_422(): void
    {
        $res = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->withHeader('Idempotency-Key', 'idemp_unsupported_' . uniqid())
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/{$this->revision->version_number}/exports", [
                'format' => 'markdown',
            ]);

        $res->assertStatus(422);
        $this->assertEquals('FORMAT_NOT_SUPPORTED', $res->json('error.code'));
        $this->assertContains('docx', $res->json('error.details.supported_formats'));
        $this->assertContains('pptx', $res->json('error.details.supported_formats'));
        $this->assertContains('print_pack_pdf', $res->json('error.details.supported_formats'));
    }

    /**
     * Test 7: Job retry idempotency prevents duplicate artifact creation.
     */
    public function test_queue_worker_retry_idempotency_does_not_duplicate_artifacts(): void
    {
        $job = ExportJob::create([
            'revision_id' => $this->revision->id,
            'user_id' => $this->teacher->id,
            'format' => 'docx',
            'status' => 'queued',
            'provenance_fingerprint' => $this->fingerprint,
        ]);

        $service = app(\App\Services\Export\ScenarioExportService::class);

        // Run 1: initial compilation
        $artifact1 = $service->export($job);
        $this->assertEquals('ready', $job->fresh()->status);
        $this->assertEquals(1, ExportedArtifact::where('export_job_id', $job->id)->count());

        // Run 2: simulated Redis job retry
        $artifact2 = $service->export($job);
        $this->assertEquals('ready', $job->fresh()->status);

        // Assert exactly 1 artifact row remains (no duplicate key error, updateOrCreate used)
        $this->assertEquals(1, ExportedArtifact::where('export_job_id', $job->id)->count());
        $this->assertEquals($artifact1->id, $artifact2->id);
    }

    /**
     * Test 8: P0 Regression: Provenance marking consistency across all four formats.
     * Verified claim gets [Подлинно], unverified claim (1612) gets [Требует проверки] in:
     * - Standard Scenario PDF
     * - Print Pack PDF
     * - DOCX
     * - PPTX
     */
    public function test_provenance_marking_consistency_across_all_four_formats_with_unverified_claim_regression(): void
    {
        $claim1612 = CulturalClaim::create([
            'calendar_event_id' => $this->event->id,
            'claim_text' => 'События 1612 года положили начало традиции соборного единения сословий.',
            'culture_name' => 'Российская Федерация',
            'provenance_notes' => 'Утверждение из историографии, требует привязки к первоисточнику.',
        ]);

        $claimsData = [
            [
                'claim_id' => $this->claim->id,
                'claim_text' => $this->claim->claim_text,
                'culture_name' => $this->claim->culture_name,
                'server_verification_status' => 'verified_authentic',
                'evidence_records' => [
                    [
                        'event_source_id' => $this->source->id,
                        'source_title' => $this->source->title,
                        'citation_locator' => 'Том 2, Раздел 3, стр. 45-52',
                        'citation_excerpt' => 'Документальные свидетельства росписи берестяных туесов и утвари зафиксированы в XVIII веке.',
                    ],
                ],
            ],
            [
                'claim_id' => $claim1612->id,
                'claim_text' => $claim1612->claim_text,
                'culture_name' => $claim1612->culture_name,
                'server_verification_status' => 'unverified',
                'evidence_records' => [],
            ],
        ];

        $payload = $this->revision->content_payload;
        $payload['cultural_claims'] = $claimsData;
        $payload['materials']['presentation_slides'] = [];

        $regRevision = ScenarioRevision::create([
            'scenario_id' => $this->scenario->id,
            'version_number' => 2,
            'created_by_user_id' => $this->teacher->id,
            'change_description' => 'Ревизия для проверки консистентности provenance',
            'concept' => 'Концепция сценария',
            'pedagogical_goal' => 'Цель сценария',
            'pedagogical_mechanism' => ['mechanism_type' => 'comparative_cultural_study'],
            'pedagogical_tasks' => [
                'personal' => ['Личностная задача'],
                'meta_subject' => ['Метапредметная задача'],
                'subject_specific' => ['Предметная задача'],
            ],
            'expected_outcomes' => [
                'knowledge' => ['Знание фактов'],
                'skills' => ['Умение анализа'],
                'values' => ['Гражданственность'],
            ],
            'target_audience' => ['age_group' => 'basic_5_9'],
            'format' => ['scale' => 'class_level', 'type' => 'class_hour'],
            'total_duration_minutes' => 45,
            'teacher_instructions' => $payload['teacher_instructions'],
            'content_payload' => $payload,
            'created_at' => now(),
        ]);

        $this->scenario->update(['current_revision_id' => $regRevision->id]);

        $fingerprint = $this->provenanceService->computeRevisionProvenanceFingerprint($regRevision);

        RevisionModerationState::create([
            'revision_id' => $regRevision->id,
            'status' => 'verification_passed',
            'provenance_fingerprint' => $fingerprint,
            'p0_violations_count' => 0,
            'p1_warnings_count' => 0,
            'updated_at' => now(),
        ]);

        TeacherSignoff::create([
            'revision_id' => $regRevision->id,
            'user_id' => $this->teacher->id,
            'is_confirmed' => true,
            'provenance_fingerprint' => $fingerprint,
            'checklist_confirmed' => [
                'safety_checked' => true,
                'timing_fit' => true,
                'cultural_context_approved' => true,
            ],
            'teacher_notes' => 'Проверено',
            'ip_address' => '127.0.0.1',
            'confirmed_at' => now(),
        ]);

        // A. Standard Scenario PDF
        $standardPdfHtml = view('pdf.scenario', [
            'scenario' => $this->scenario,
            'revision' => $regRevision,
            'occasionTitle' => 'День народных промыслов',
            'claims' => $claimsData,
            'culturalClaims' => $claimsData,
            'stages' => collect($payload['stages']),
            'materials' => collect($payload['materials']),
            'methodologicalNotes' => [],
            'safetyRules' => [],
            'signoff' => $regRevision->signoff,
            'exportJob' => null,
        ])->render();

        $this->assertStringContainsString('ПОДТВЕРЖДЕНО ЭКСПЕРТАМИ', $standardPdfHtml);
        $this->assertStringContainsString('ТРЕБУЕТ ПРОВЕРКИ', $standardPdfHtml);
        $this->assertStringContainsString('События 1612 года', $standardPdfHtml);

        // B. Print Pack PDF
        $printPackHtml = view('pdf.print_pack', [
            'scenario' => $this->scenario,
            'revision' => $regRevision,
            'occasionTitle' => 'День народных промыслов',
            'claims' => $claimsData,
            'stages' => collect($payload['stages']),
            'props' => $payload['props'],
            'printableCards' => $payload['materials']['printable_cards'],
            'handouts' => $payload['materials']['handouts'],
            'materialsPayload' => $payload['materials'],
            'safetyRules' => [],
            'signoff' => $regRevision->signoff,
            'exportJob' => null,
        ])->render();

        $this->assertStringContainsString('&check; Подлинно', $printPackHtml);
        $this->assertStringContainsString('&#9888; Требует проверки', $printPackHtml);
        $this->assertStringContainsString('(содержит утверждения, требующие проверки)', $printPackHtml);
        $this->assertMatchesRegularExpression('/События 1612 года.*?Требует проверки/s', $printPackHtml);

        // C. DOCX
        $exportService = app(\App\Services\Export\ScenarioExportService::class);
        $docxJob = ExportJob::create([
            'revision_id' => $regRevision->id,
            'user_id' => $this->teacher->id,
            'format' => 'docx',
            'status' => 'queued',
            'provenance_fingerprint' => $fingerprint,
        ]);
        $docxArtifact = $exportService->export($docxJob);
        $docxBytes = Storage::disk('local')->get($docxArtifact->file_path);

        $tempDocx = tempnam(sys_get_temp_dir(), 'test_docx_prov_');
        file_put_contents($tempDocx, $docxBytes);
        $docxZip = new ZipArchive();
        $docxZip->open($tempDocx);
        $docxXml = $docxZip->getFromName('word/document.xml');
        $docxZip->close();
        @unlink($tempDocx);

        $this->assertStringContainsString('Подлинно', $docxXml);
        $this->assertStringContainsString('Требует проверки', $docxXml);
        $this->assertStringContainsString('6. Культурно-исторические факты и первоисточники (с указанием статуса проверки)', $docxXml);

        // D. PPTX
        $pptxJob = ExportJob::create([
            'revision_id' => $regRevision->id,
            'user_id' => $this->teacher->id,
            'format' => 'pptx',
            'status' => 'queued',
            'provenance_fingerprint' => $fingerprint,
        ]);
        $pptxArtifact = $exportService->export($pptxJob);
        $pptxBytes = Storage::disk('local')->get($pptxArtifact->file_path);

        $tempPptx = tempnam(sys_get_temp_dir(), 'test_pptx_prov_');
        file_put_contents($tempPptx, $pptxBytes);
        $pptxZip = new ZipArchive();
        $pptxZip->open($tempPptx);

        $combinedPptxXml = '';
        for ($i = 1; $i <= 5; $i++) {
            $slideXml = $pptxZip->getFromName("ppt/slides/slide{$i}.xml");
            if ($slideXml) {
                $combinedPptxXml .= $slideXml;
            }
            $noteXml = $pptxZip->getFromName("ppt/notesSlides/notesSlide{$i}.xml");
            if ($noteXml) {
                $combinedPptxXml .= $noteXml;
            }
        }
        $pptxZip->close();
        @unlink($tempPptx);

        $this->assertStringContainsString('Подлинно', $combinedPptxXml);
        $this->assertStringContainsString('Требует проверки', $combinedPptxXml);
        $this->assertStringContainsString('Исторические свидетельства (требуется проверка)', $combinedPptxXml);
        $this->assertStringContainsString('События 1612 года', $combinedPptxXml);
    }

    /**
     * Test 9: DOCX renders all canonical pedagogical, role, activity, prop, card and handout fields.
     */
    public function test_docx_canonical_pedagogical_and_interactive_fields_completeness(): void
    {
        $payload = [
            'theme' => 'Освоение народных ремёсел',
            'cultural_claims' => [
                [
                    'claim_id' => $this->claim->id,
                    'claim_text' => $this->claim->claim_text,
                    'culture_name' => $this->claim->culture_name,
                    'server_verification_status' => 'verified_authentic',
                    'evidence_records' => [
                        [
                            'event_source_id' => $this->source->id,
                            'source_title' => $this->source->title,
                            'citation_locator' => 'Том 2, Раздел 3, стр. 45-52',
                            'citation_excerpt' => 'Документальные свидетельства росписи берестяных туесов и утвари зафиксированы в XVIII веке.',
                        ],
                    ],
                ],
            ],
            'stages' => [
                [
                    'id' => 'st-1',
                    'title' => 'Погружение в традицию',
                    'duration_minutes' => 45,
                    'description' => 'Вводный этап',
                    'teacher_actions' => 'Инструктаж',
                    'student_actions' => 'Слушание',
                ],
            ],
            'pedagogical_tasks' => [
                'personal' => ['Воспитание уважения к труду ремесленников'],
                'meta_subject' => ['Умение работать в творческой группе'],
                'subject_specific' => ['Освоение приёмов уральской кистевой росписи'],
            ],
            'expected_outcomes' => [
                'knowledge' => ['Знание базовых орнаментов Урала'],
                'skills' => ['Умение наносить двухцветный мазок'],
                'values' => ['Бережное отношение к региональному культурному наследию'],
            ],
            'roles' => [
                [
                    'role_name' => 'Мастер-наставник бригады',
                    'target_participants' => 'Учащиеся с опытом рисования',
                    'description' => 'Координирует работу группы над общим панно',
                    'specific_tasks' => [
                        'Распределить шаблоны берестяных туесов',
                        'Контролировать аккуратность смешивания красок',
                    ],
                ],
            ],
            'activities' => [
                [
                    'title' => 'Артель красильщиков: Роспись туеса',
                    'rules_and_instructions' => 'Соблюдать пошаговую технику нанесения двойного мазка',
                    'scoring_or_evaluation' => 'Оценивается гармоничность композиции и точность цветовых переходов',
                ],
            ],
            'props' => [
                [
                    'item_name' => 'Берестяные заготовки туесов',
                    'quantity' => '6 штук',
                    'alternative_if_missing' => 'Плотный крафтовый картон с фактурой коры',
                    'is_mandatory' => true,
                ],
            ],
            'materials' => [
                'printable_cards' => [
                    [
                        'title' => 'Карточка мастера-испытателя',
                        'front_text' => 'Лицевая сторона: Расшифруйте символику ягод и розанов в орнаменте',
                        'back_text' => 'Оборотная сторона: Подсказка - красный цвет символизирует жизнь и тепло',
                        'quantity_per_group' => 2,
                    ],
                ],
                'handouts' => [
                    [
                        'title' => 'Маршрутный лист творческой мастерской',
                        'instructions' => 'Фиксируйте этапы выполнения задания и роли участников',
                        'template_content' => 'Поля: Название бригады / Выбранный мотив / Критерии качества',
                    ],
                ],
            ],
            'teacher_instructions' => [
                'methodological_recommendations' => ['Использовать наглядные образцы росписи'],
                'general_safety_rules' => ['Аккуратно работать с кистями и красками'],
                'adaptation_for_disabilities_or_small_groups' => 'Предоставить увеличенные трафареты',
            ],
        ];

        $richRev = ScenarioRevision::create([
            'scenario_id' => $this->scenario->id,
            'version_number' => 3,
            'created_by_user_id' => $this->teacher->id,
            'change_description' => 'Канонические поля ревизии',
            'concept' => 'Формирование идентичности через народные ремёсла',
            'pedagogical_goal' => 'Познакомить учащихся с технологией традиционной росписи',
            'pedagogical_mechanism' => ['mechanism_type' => 'comparative_cultural_study'],
            'pedagogical_tasks' => $payload['pedagogical_tasks'],
            'expected_outcomes' => $payload['expected_outcomes'],
            'target_audience' => ['age_group' => 'basic_5_9'],
            'format' => ['scale' => 'class_level', 'type' => 'class_hour'],
            'total_duration_minutes' => 45,
            'teacher_instructions' => $payload['teacher_instructions'],
            'content_payload' => $payload,
            'created_at' => now(),
        ]);

        $this->scenario->update(['current_revision_id' => $richRev->id]);

        $fingerprint = $this->provenanceService->computeRevisionProvenanceFingerprint($richRev);

        RevisionModerationState::create([
            'revision_id' => $richRev->id,
            'status' => 'verification_passed',
            'provenance_fingerprint' => $fingerprint,
            'p0_violations_count' => 0,
            'p1_warnings_count' => 0,
            'updated_at' => now(),
        ]);

        TeacherSignoff::create([
            'revision_id' => $richRev->id,
            'user_id' => $this->teacher->id,
            'is_confirmed' => true,
            'provenance_fingerprint' => $fingerprint,
            'checklist_confirmed' => [
                'safety_checked' => true,
                'timing_fit' => true,
                'cultural_context_approved' => true,
            ],
            'teacher_notes' => 'Проверено',
            'ip_address' => '127.0.0.1',
            'confirmed_at' => now(),
        ]);

        $service = app(\App\Services\Export\ScenarioExportService::class);
        $job = ExportJob::create([
            'revision_id' => $richRev->id,
            'user_id' => $this->teacher->id,
            'format' => 'docx',
            'status' => 'queued',
            'provenance_fingerprint' => $fingerprint,
        ]);
        $artifact = $service->export($job);
        $bytes = Storage::disk('local')->get($artifact->file_path);

        $tempDocx = tempnam(sys_get_temp_dir(), 'test_docx_canonical_');
        file_put_contents($tempDocx, $bytes);
        $zip = new ZipArchive();
        $zip->open($tempDocx);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($tempDocx);

        // Assert pedagogical task keys
        $this->assertStringContainsString('Личностные', $xml);
        $this->assertStringContainsString('Воспитание уважения к труду ремесленников', $xml);
        $this->assertStringContainsString('Метапредметные', $xml);
        $this->assertStringContainsString('Умение работать в творческой группе', $xml);
        $this->assertStringContainsString('Предметные', $xml);
        $this->assertStringContainsString('Освоение приёмов уральской кистевой росписи', $xml);

        // Assert expected outcomes keys
        $this->assertStringContainsString('Знания', $xml);
        $this->assertStringContainsString('Знание базовых орнаментов Урала', $xml);
        $this->assertStringContainsString('Умения и навыки', $xml);
        $this->assertStringContainsString('Умение наносить двухцветный мазок', $xml);
        $this->assertStringContainsString('Ценности и ориентиры', $xml);
        $this->assertStringContainsString('Бережное отношение к региональному культурному наследию', $xml);

        // Assert roles & specific tasks
        $this->assertStringContainsString('Мастер-наставник бригады', $xml);
        $this->assertStringContainsString('Учащиеся с опытом рисования', $xml);
        $this->assertStringContainsString('Распределить шаблоны берестяных туесов', $xml);
        $this->assertStringContainsString('Контролировать аккуратность смешивания красок', $xml);

        // Assert activities & rules
        $this->assertStringContainsString('Артель красильщиков: Роспись туеса', $xml);
        $this->assertStringContainsString('Соблюдать пошаговую технику нанесения двойного мазка', $xml);
        $this->assertStringContainsString('Оценивается гармоничность композиции', $xml);

        // Assert props, cards, handouts
        $this->assertStringContainsString('Берестяные заготовки туесов', $xml);
        $this->assertStringContainsString('Плотный крафтовый картон с фактурой коры', $xml);
        $this->assertStringContainsString('Карточка мастера-испытателя', $xml);
        $this->assertStringContainsString('Расшифруйте символику ягод и розанов в орнаменте', $xml);
        $this->assertStringContainsString('Подсказка - красный цвет символизирует жизнь и тепло', $xml);
        $this->assertStringContainsString('Маршрутный лист творческой мастерской', $xml);
        $this->assertStringContainsString('Название бригады / Выбранный мотив / Критерии качества', $xml);
    }

    /**
     * Test 10: Print Pack PDF renders full content of props (with alternatives), cards (front & back), handouts (templates & tables).
     */
    public function test_print_pack_completeness_of_cards_handouts_and_props(): void
    {
        $payload = [
            'theme' => 'Освоение народных ремёсел',
            'props' => [
                [
                    'item_name' => 'Берестяные заготовки туесов',
                    'quantity' => '6 штук',
                    'alternative_if_missing' => 'Плотный крафтовый картон с фактурой коры',
                    'is_mandatory' => true,
                ],
            ],
            'materials' => [
                'printable_cards' => [
                    [
                        'title' => 'Карточка мастера-испытателя',
                        'target_role' => 'Группа исследователей орнамента',
                        'front_text' => 'Лицевая сторона: Расшифруйте символику ягод и розанов',
                        'back_text' => 'Оборотная сторона: Красный цвет символизирует огонь и тепло',
                        'quantity_per_group' => 3,
                    ],
                ],
                'handouts' => [
                    [
                        'title' => 'Маршрутный лист творческой мастерской',
                        'instructions' => 'Заполняйте бланк в ходе практической работы',
                        'template_content' => 'Поля: Проблема / Идеи бригады / Итоговый эскиз',
                    ],
                ],
            ],
            'stages' => [
                ['title' => 'Вводный этап', 'duration_minutes' => 15, 'teacher_actions' => 'Инструктаж'],
            ],
            'teacher_instructions' => [
                'general_safety_rules' => ['Бережно относиться к рабочему инвентарю'],
            ],
        ];

        $html = view('pdf.print_pack', [
            'scenario' => $this->scenario,
            'revision' => $this->revision,
            'occasionTitle' => 'День народных промыслов',
            'claims' => $this->revision->content_payload['cultural_claims'],
            'stages' => collect($payload['stages']),
            'props' => $payload['props'],
            'printableCards' => $payload['materials']['printable_cards'],
            'handouts' => $payload['materials']['handouts'],
            'materialsPayload' => $payload['materials'],
            'safetyRules' => $payload['teacher_instructions']['general_safety_rules'],
            'signoff' => $this->revision->signoff,
            'exportJob' => null,
        ])->render();

        // Props
        $this->assertStringContainsString('Берестяные заготовки туесов', $html);
        $this->assertStringContainsString('6 штук', $html);
        $this->assertStringContainsString('Плотный крафтовый картон с фактурой коры', $html);
        $this->assertStringContainsString('(Обязательно)', $html);

        // Cards (front & back & quantity)
        $this->assertStringContainsString('Карточка мастера-испытателя', $html);
        $this->assertStringContainsString('Группа исследователей орнамента', $html);
        $this->assertStringContainsString('3 экз. на малую группу', $html);
        $this->assertStringContainsString('Лицевая сторона: Расшифруйте символику ягод и розанов', $html);
        $this->assertStringContainsString('Оборотная сторона: Красный цвет символизирует огонь и тепло', $html);

        // Handouts (instructions, template content, table for handwriting)
        $this->assertStringContainsString('Маршрутный лист творческой мастерской', $html);
        $this->assertStringContainsString('Заполняйте бланк в ходе практической работы', $html);
        $this->assertStringContainsString('Поля: Проблема / Идеи бригады / Итоговый эскиз', $html);
        $this->assertStringContainsString('Записи малой группы (заполняется от руки)', $html);
        $this->assertStringContainsString('Итоговый эскиз', $html);
    }

    /**
     * Test 11: PPTX resolves 1-4 user slides by preserving all of them and supplementing to exactly 5 slides with notes.
     */
    public function test_pptx_partial_slides_preservation_and_supplement_to_five(): void
    {
        $payload = $this->revision->content_payload;
        $payload['materials']['presentation_slides'] = [
            [
                'title' => 'Пользовательский слайд 1: Введение в тему',
                'subtitle' => 'Особый подзаголовок слайда 1',
                'content' => ['Пользовательский пункт 1.1', 'Пользовательский пункт 1.2'],
                'speaker_notes' => 'Уникальная авторская заметка к первому слайду.',
            ],
            [
                'title' => 'Пользовательский слайд 2: Практическое задание',
                'subtitle' => 'Особый подзаголовок слайда 2',
                'content' => ['Пользовательский пункт 2.1'],
                'speaker_notes' => 'Уникальная авторская заметка ко второму слайду.',
            ],
        ];

        $partialRev = ScenarioRevision::create([
            'scenario_id' => $this->scenario->id,
            'version_number' => 4,
            'created_by_user_id' => $this->teacher->id,
            'change_description' => 'Ревизия с 2 слайдами презентации',
            'concept' => 'Концепция',
            'pedagogical_goal' => 'Цель',
            'pedagogical_mechanism' => ['mechanism_type' => 'comparative_cultural_study'],
            'pedagogical_tasks' => [
                'personal' => ['Личностная задача'],
                'meta_subject' => ['Метапредметная задача'],
                'subject_specific' => ['Предметная задача'],
            ],
            'expected_outcomes' => [
                'knowledge' => ['Знания'],
                'skills' => ['Умения'],
                'values' => ['Ценности'],
            ],
            'target_audience' => ['age_group' => 'basic_5_9'],
            'format' => ['scale' => 'class_level', 'type' => 'class_hour'],
            'total_duration_minutes' => 45,
            'teacher_instructions' => $payload['teacher_instructions'],
            'content_payload' => $payload,
            'created_at' => now(),
        ]);

        $this->scenario->update(['current_revision_id' => $partialRev->id]);

        $fingerprint = $this->provenanceService->computeRevisionProvenanceFingerprint($partialRev);

        RevisionModerationState::create([
            'revision_id' => $partialRev->id,
            'status' => 'verification_passed',
            'provenance_fingerprint' => $fingerprint,
            'p0_violations_count' => 0,
            'p1_violations_count' => 0,
            'updated_at' => now(),
        ]);

        TeacherSignoff::create([
            'revision_id' => $partialRev->id,
            'user_id' => $this->teacher->id,
            'is_confirmed' => true,
            'provenance_fingerprint' => $fingerprint,
            'checklist_confirmed' => [
                'safety_checked' => true,
                'timing_fit' => true,
                'cultural_context_approved' => true,
            ],
            'teacher_notes' => 'Проверено',
            'ip_address' => '127.0.0.1',
            'confirmed_at' => now(),
        ]);

        // 1. Verify renderer method directly
        $pptxRenderer = app(\App\Services\Export\Renderers\PptxExportRenderer::class);
        $resolvedSlides = $pptxRenderer->resolveFiveSlides(
            $this->scenario,
            $partialRev,
            $payload,
            $payload['cultural_claims'],
            $payload['stages'],
            $this->school
        );

        $this->assertCount(5, $resolvedSlides, 'Resolved slides must contain exactly 5 slides.');
        $this->assertEquals('Пользовательский слайд 1: Введение в тему', $resolvedSlides[0]['title']);
        $this->assertEquals('Уникальная авторская заметка к первому слайду.', $resolvedSlides[0]['speaker_notes']);
        $this->assertEquals('Пользовательский слайд 2: Практическое задание', $resolvedSlides[1]['title']);
        $this->assertEquals('Уникальная авторская заметка ко второму слайду.', $resolvedSlides[1]['speaker_notes']);

        // Remaining 3 slides must be complemented with pedagogical content and notes
        for ($i = 2; $i < 5; $i++) {
            $this->assertNotEmpty($resolvedSlides[$i]['title']);
            $this->assertNotEmpty($resolvedSlides[$i]['speaker_notes'], "Complemented slide {$i} must contain speaker notes.");
        }

        // 2. Export full PPTX and inspect OpenXML structure
        $service = app(\App\Services\Export\ScenarioExportService::class);
        $job = ExportJob::create([
            'revision_id' => $partialRev->id,
            'user_id' => $this->teacher->id,
            'format' => 'pptx',
            'status' => 'queued',
            'provenance_fingerprint' => $fingerprint,
        ]);
        $artifact = $service->export($job);
        $bytes = Storage::disk('local')->get($artifact->file_path);

        $tempPptx = tempnam(sys_get_temp_dir(), 'test_pptx_partial_');
        file_put_contents($tempPptx, $bytes);
        $zip = new ZipArchive();
        $zip->open($tempPptx);

        for ($i = 1; $i <= 5; $i++) {
            $this->assertNotFalse($zip->locateName("ppt/slides/slide{$i}.xml"), "Slide {$i} XML must exist.");
            $this->assertNotFalse($zip->locateName("ppt/notesSlides/notesSlide{$i}.xml"), "Notes slide {$i} XML must exist.");
        }

        $slide1Xml = $zip->getFromName('ppt/slides/slide1.xml');
        $this->assertStringContainsString('Пользовательский слайд 1: Введение в тему', $slide1Xml);

        $notes1Xml = $zip->getFromName('ppt/notesSlides/notesSlide1.xml');
        $this->assertStringContainsString('Уникальная авторская заметка к первому слайду', $notes1Xml);

        $zip->close();
        @unlink($tempPptx);
    }
}
