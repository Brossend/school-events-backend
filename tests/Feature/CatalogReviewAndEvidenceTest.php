<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\CulturalClaim;
use App\Models\CulturalClaimSource;
use App\Models\EventSource;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\SourceReview;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CatalogReviewAndEvidenceTest extends TestCase
{
    use DatabaseTransactions;

    protected User $curatorGlobal;
    protected User $curator66;
    protected User $curator77;
    protected User $admin;
    protected User $teacher;
    protected School $school;
    protected CalendarEvent $eventFederal;
    protected CalendarEvent $event66;
    protected CalendarEvent $event77;
    protected EventSource $source66;
    protected EventSource $sourceFederal;
    protected CulturalClaim $claim66;

    protected function setUp(): void
    {
        parent::setUp();

        $this->curatorGlobal = User::factory()->curator('global')->create();
        $this->curator66 = User::factory()->curator('regional', ['66'])->create();
        $this->curator77 = User::factory()->curator('regional', ['77'])->create();
        $this->admin = User::factory()->systemAdmin()->create();
        $this->teacher = User::factory()->create();

        $this->school = School::create([
            'name' => 'Лицей 130',
            'region_code' => '66',
            'city' => 'Екатеринбург',
            'inn' => '6670012345',
            'settings' => [],
        ]);

        SchoolMembership::create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);

        $this->eventFederal = CalendarEvent::create([
            'title' => 'День славянской письменности',
            'event_type' => 'federal',
            'region_code' => null,
            'date_type' => 'fixed_annual',
            'fixed_month' => 5,
            'fixed_day' => 24,
            'description' => 'Федеральный праздник славянской культуры.',
            'target_age_groups' => ['primary_1_4', 'basic_5_9'],
            'recommended_value_directions' => ['historical_memory'],
            'status' => 'approved',
        ]);

        $this->event66 = CalendarEvent::create([
            'title' => 'День бажовских сказов',
            'event_type' => 'regional',
            'region_code' => '66',
            'date_type' => 'fixed_annual',
            'fixed_month' => 1,
            'fixed_day' => 27,
            'description' => 'Уральский фольклорный праздник.',
            'target_age_groups' => ['basic_5_9'],
            'recommended_value_directions' => ['cultural_diversity'],
            'status' => 'approved',
        ]);

        $this->event77 = CalendarEvent::create([
            'title' => 'День города Москвы',
            'event_type' => 'regional',
            'region_code' => '77',
            'date_type' => 'fixed_annual',
            'fixed_month' => 9,
            'fixed_day' => 10,
            'description' => 'Московский городской праздник.',
            'target_age_groups' => ['basic_5_9'],
            'recommended_value_directions' => ['civic_responsibility'],
            'status' => 'approved',
        ]);

        $this->source66 = EventSource::create([
            'calendar_event_id' => $this->event66->id,
            'source_type' => 'ethnographic_archive',
            'title' => 'Уральский фольклорный сборник',
            'author' => 'Бажов П.П.',
            'url' => 'https://archive.urfu.ru/bazhov',
            'doi_or_isbn' => '978-5-12345-678-9',
        ]);

        $this->sourceFederal = EventSource::create([
            'calendar_event_id' => $this->eventFederal->id,
            'source_type' => 'scientific_publication',
            'title' => 'История славянской азбуки',
            'author' => 'Академия наук',
            'url' => 'https://ras.ru/slavonic',
        ]);

        $this->claim66 = CulturalClaim::create([
            'calendar_event_id' => $this->event66->id,
            'claim_text' => 'Традиция сказа о Хозяйке Медной горы возникла среди уральских горняков в XIX веке.',
            'culture_name' => 'Горнозаводская культура Урала',
            'provenance_notes' => 'Подтверждено этнографическими материалами.',
        ]);
    }

    public function test_review_queue_requires_curator_or_admin(): void
    {
        $response = $this->actingAs($this->teacher)
            ->getJson('/api/v1/catalog/review-queue');

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_CURATOR_ACCESS');
    }

    public function test_curator_can_view_review_queue_with_regional_scoping(): void
    {
        // Global curator sees both
        $resGlobal = $this->actingAs($this->curatorGlobal)
            ->getJson('/api/v1/catalog/review-queue?status=all');

        $resGlobal->assertStatus(200);
        $ids = collect($resGlobal->json('data'))->pluck('id')->all();
        $this->assertContains($this->source66->id, $ids);
        $this->assertContains($this->sourceFederal->id, $ids);

        // Curator 77 cannot see regional event 66
        $res77 = $this->actingAs($this->curator77)
            ->getJson('/api/v1/catalog/review-queue?status=all');

        $res77->assertStatus(200);
        $ids77 = collect($res77->json('data'))->pluck('id')->all();
        $this->assertNotContains($this->source66->id, $ids77);
        // But curator 77 can see federal event
        $this->assertContains($this->sourceFederal->id, $ids77);
    }

    public function test_get_event_source_details(): void
    {
        $response = $this->actingAs($this->curatorGlobal)
            ->getJson("/api/v1/event-sources/{$this->source66->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $this->source66->id)
            ->assertJsonPath('data.title', 'Уральский фольклорный сборник')
            ->assertJsonPath('data.author', 'Бажов П.П.')
            ->assertJsonPath('data.source_type', 'ethnographic_archive');
    }

    public function test_patch_event_source_bibliographic_details(): void
    {
        $response = $this->actingAs($this->curator66)
            ->patchJson("/api/v1/event-sources/{$this->source66->id}", [
                'title' => 'Уральский фольклорный сборник (расширенное издание)',
                'doi_or_isbn' => '978-5-99999-000-1',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.title', 'Уральский фольклорный сборник (расширенное издание)')
            ->assertJsonPath('data.doi_or_isbn', '978-5-99999-000-1');

        $this->assertDatabaseHas('event_sources', [
            'id' => $this->source66->id,
            'title' => 'Уральский фольклорный сборник (расширенное издание)',
        ]);
    }

    public function test_regional_curator_cannot_patch_event_source_outside_region(): void
    {
        $response = $this->actingAs($this->curator77)
            ->patchJson("/api/v1/event-sources/{$this->source66->id}", [
                'title' => 'Попытка изменения источника чужого региона',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_REGIONAL_SCOPE');
    }

    public function test_post_review_approved_updates_source_and_claim_status(): void
    {
        // First attach an evidence record linking source66 to claim66 with status unverified
        $evidence = CulturalClaimSource::create([
            'calendar_event_id' => $this->event66->id,
            'cultural_claim_id' => $this->claim66->id,
            'event_source_id' => $this->source66->id,
            'citation_locator' => 'Том 1, стр. 42',
            'citation_excerpt' => 'Сказ о Хозяйке Медной горы впервые записан в полевых экспедициях.',
            'verification_status' => 'unverified',
        ]);

        $response = $this->actingAs($this->curator66)
            ->postJson("/api/v1/event-sources/{$this->source66->id}/reviews", [
                'decision' => 'approved',
                'comments' => 'Источник подлинный, издание сверено с академическим фондом.',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.decision', 'approved')
            ->assertJsonPath('data.reviewer.id', $this->curator66->id);

        $this->assertDatabaseHas('source_reviews', [
            'event_source_id' => $this->source66->id,
            'decision' => 'approved',
        ]);

        $this->source66->refresh();
        $this->assertEquals($this->curator66->id, $this->source66->verified_by_user_id);
        $this->assertNotNull($this->source66->verification_date);

        $evidence->refresh();
        // Under BE-006.1 P0 separation: source approval does NOT auto-promote claims to verified_authentic
        $this->assertEquals('unverified', $evidence->verification_status);
        $this->assertNull($evidence->verified_by_user_id);
    }

    public function test_post_review_rejected_invalidates_claims_to_rejected_fake(): void
    {
        // Attach evidence record
        $evidence = CulturalClaimSource::create([
            'calendar_event_id' => $this->event66->id,
            'cultural_claim_id' => $this->claim66->id,
            'event_source_id' => $this->source66->id,
            'citation_locator' => 'Том 1, стр. 42',
            'citation_excerpt' => 'Сказ о Хозяйке Медной горы впервые записан в полевых экспедициях.',
            'verification_status' => 'verified_authentic',
            'verified_by_user_id' => $this->curator66->id,
            'verified_at' => now(),
        ]);

        // Curator rejects source
        $response = $this->actingAs($this->curator66)
            ->postJson("/api/v1/event-sources/{$this->source66->id}/reviews", [
                'decision' => 'rejected',
                'comments' => 'Обнаружена историческая мистификация и фальсификация первоисточника.',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.decision', 'rejected');

        $evidence->refresh();
        $this->assertEquals('rejected_fake', $evidence->verification_status);

        $this->source66->refresh();
        $this->assertNull($this->source66->verified_by_user_id);
    }

    public function test_post_review_requires_amendment_resets_verified_claims_to_unverified(): void
    {
        $evidence = CulturalClaimSource::create([
            'calendar_event_id' => $this->event66->id,
            'cultural_claim_id' => $this->claim66->id,
            'event_source_id' => $this->source66->id,
            'citation_locator' => 'Том 1, стр. 42',
            'citation_excerpt' => 'Сказ о Хозяйке Медной горы впервые записан в полевых экспедициях.',
            'verification_status' => 'verified_authentic',
            'verified_by_user_id' => $this->curator66->id,
            'verified_at' => now(),
        ]);

        $response = $this->actingAs($this->curator66)
            ->postJson("/api/v1/event-sources/{$this->source66->id}/reviews", [
                'decision' => 'requires_amendment',
                'comments' => 'Требуется уточнить номер фонда и архивные листы.',
            ]);

        $response->assertStatus(201);

        $evidence->refresh();
        $this->assertEquals('unverified', $evidence->verification_status);
        $this->assertNull($evidence->verified_by_user_id);
    }

    public function test_regional_curator_cannot_review_federal_event_source(): void
    {
        $response = $this->actingAs($this->curator66)
            ->postJson("/api/v1/event-sources/{$this->sourceFederal->id}/reviews", [
                'decision' => 'approved',
                'comments' => 'Попытка рецензировать федеральное событие.',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_REGIONAL_SCOPE');
    }

    public function test_list_event_source_reviews(): void
    {
        SourceReview::create([
            'event_source_id' => $this->source66->id,
            'reviewer_user_id' => $this->curator66->id,
            'decision' => 'approved',
            'comments' => 'Первая рецензия.',
            'reviewed_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->curator66)
            ->getJson("/api/v1/event-sources/{$this->source66->id}/reviews");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.decision', 'approved')
            ->assertJsonPath('data.0.comments', 'Первая рецензия.');
    }

    public function test_get_cultural_claim_details(): void
    {
        $response = $this->actingAs($this->curator66)
            ->getJson("/api/v1/cultural-claims/{$this->claim66->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $this->claim66->id)
            ->assertJsonPath('data.claim_text', $this->claim66->claim_text)
            ->assertJsonPath('data.culture_name', 'Горнозаводская культура Урала');
    }

    public function test_attach_evidence_to_cultural_claim(): void
    {
        // Approve source first
        SourceReview::create([
            'event_source_id' => $this->source66->id,
            'reviewer_user_id' => $this->curator66->id,
            'decision' => 'approved',
            'comments' => 'Одобрено.',
            'reviewed_at' => now(),
        ]);
        $this->source66->update([
            'verified_by_user_id' => $this->curator66->id,
            'verification_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($this->curator66)
            ->postJson("/api/v1/cultural-claims/{$this->claim66->id}/evidence", [
                'event_source_id' => $this->source66->id,
                'citation_locator' => 'Том 2, глава 3, стр. 115',
                'citation_excerpt' => 'Подтверждающий текст из этнографического источника Бажова.',
                'applicable_academic_year' => '2026-2027',
                'verify' => true,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.verification_status', 'verified_authentic')
            ->assertJsonPath('data.citation_locator', 'Том 2, глава 3, стр. 115');

        $this->assertDatabaseHas('cultural_claim_sources', [
            'cultural_claim_id' => $this->claim66->id,
            'event_source_id' => $this->source66->id,
            'verification_status' => 'verified_authentic',
        ]);
    }

    public function test_attach_evidence_cross_event_injection_blocked(): void
    {
        // Attempt linking sourceFederal to claim66 (different calendar events)
        $response = $this->actingAs($this->curatorGlobal)
            ->postJson("/api/v1/cultural-claims/{$this->claim66->id}/evidence", [
                'event_source_id' => $this->sourceFederal->id,
                'citation_locator' => 'Том 1, стр. 1',
                'citation_excerpt' => 'Текст цитаты из другого события.',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'CROSS_EVENT_BINDING_FORBIDDEN');
    }

    public function test_detach_evidence_record(): void
    {
        $evidence = CulturalClaimSource::create([
            'calendar_event_id' => $this->event66->id,
            'cultural_claim_id' => $this->claim66->id,
            'event_source_id' => $this->source66->id,
            'citation_locator' => 'Том 1, стр. 42',
            'citation_excerpt' => 'Сказ о Хозяйке Медной горы впервые записан в полевых экспедициях.',
            'verification_status' => 'unverified',
        ]);

        $response = $this->actingAs($this->curator66)
            ->deleteJson("/api/v1/cultural-claims/{$this->claim66->id}/evidence/{$this->source66->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.message', 'Свидетельство первоисточника успешно отвязано от утверждения.');

        $this->assertDatabaseHas('cultural_claim_sources', [
            'id' => $evidence->id,
            'is_detached' => true,
            'detached_by_user_id' => $this->curator66->id,
        ]);
    }
}
