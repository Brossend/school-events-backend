<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\CulturalClaim;
use App\Models\CulturalClaimEvidenceAuditEvent;
use App\Models\CulturalClaimSource;
use App\Models\EventOccurrence;
use App\Models\EventSource;
use App\Models\PilotResponse;
use App\Models\ScenarioRequest;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\SourceReview;
use App\Models\User;
use App\Services\ContextSnapshotBuilder;
use App\Services\Llm\GenerationOutputValidator;
use App\Services\Llm\MockLlmProvider;
use App\Services\Llm\ScenarioSemanticValidator;
use App\Services\PilotService;
use App\Services\ProvenanceVerificationService;
use App\Services\ScenarioComparisonService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class FinalIntegrationRegressionTest extends TestCase
{
    use DatabaseTransactions;

    protected function context(): array
    {
        $school = School::create(['name' => 'FE012 isolated school', 'region_code' => '66', 'city' => 'Test', 'settings' => []]);
        $teacher = User::factory()->create();
        $curator = User::factory()->curator('regional', ['66'])->create();
        SchoolMembership::create(['school_id' => $school->id, 'user_id' => $teacher->id, 'role' => 'teacher', 'is_default' => true]);
        $event = CalendarEvent::create(['title' => 'FE012 provenance event', 'event_type' => 'regional', 'region_code' => '66', 'date_type' => 'fixed_annual', 'fixed_month' => 12, 'fixed_day' => 31, 'description' => 'Synthetic integration', 'target_age_groups' => ['basic_5_9'], 'recommended_value_directions' => ['mutual_aid'], 'status' => 'approved']);
        $source = EventSource::create(['calendar_event_id' => $event->id, 'source_type' => 'scientific_publication', 'title' => 'FE012 source', 'author' => 'Synthetic author']);
        SourceReview::create(['event_source_id' => $source->id, 'reviewer_user_id' => $curator->id, 'decision' => 'approved', 'comments' => 'Synthetic source review']);
        $claims = [];
        foreach (['first', 'second'] as $label) {
            $claim = CulturalClaim::create(['calendar_event_id' => $event->id, 'claim_text' => 'FE012 '.$label.' claim', 'culture_name' => 'Synthetic context', 'provenance_notes' => 'Test only']);
            app(ProvenanceVerificationService::class)->verifyClaim(claim: $claim, source: $source, citationLocator: 'Chapter 12, page 31', verifier: $curator, evidenceExcerpt: 'FE012 synthetic documentary evidence with sufficient citation detail.', academicYear: '2026-2027', notes: 'Test attestation');
            $claims[] = $claim;
        }

        return compact('school', 'teacher', 'curator', 'event', 'source', 'claims');
    }

    public function test_two_verified_claims_have_one_amendment_audit_each(): void
    {
        extract($this->context());
        $ids = CulturalClaimSource::where('event_source_id', $source->id)->pluck('id');
        $this->assertSame(2, CulturalClaimSource::whereIn('id', $ids)->where('verification_status', 'verified_authentic')->count());
        $this->actingAs($curator)->patchJson('/api/v1/event-sources/'.$source->id, ['author' => 'Amended author'])->assertOk();
        $this->assertSame(2, CulturalClaimSource::whereIn('id', $ids)->where('verification_status', 'unverified')->count());
        foreach ($ids as $id) {
            $this->assertSame(1, CulturalClaimEvidenceAuditEvent::where('cultural_claim_source_id', $id)->where('action', 'invalidated_by_source_amendment')->count());
        }
        $this->actingAs($curator)->patchJson('/api/v1/event-sources/'.$source->id, ['author' => 'Amended author'])->assertOk();
        $this->assertSame(2, CulturalClaimEvidenceAuditEvent::whereIn('cultural_claim_source_id', $ids)->where('action', 'invalidated_by_source_amendment')->count());
        SourceReview::create(['event_source_id' => $source->id, 'reviewer_user_id' => $curator->id, 'decision' => 'approved', 'comments' => 'Explicit source reapproval']);
        $this->assertSame(2, CulturalClaimSource::whereIn('id', $ids)->where('verification_status', 'unverified')->count());
    }

    protected function scenarioRequest(array $c, string $scale = 'class_level'): ScenarioRequest
    {
        $data = ['calendar_event_id' => $c['event']->id, 'scale' => $scale, 'format_type' => $scale === 'class_level' ? 'class_hour' : 'festival',
            'target_grades' => [5, 6], 'participants_count' => 30, 'duration_minutes' => $scale === 'class_level' ? 45 : 90,
            'value_direction' => 'family', 'family_involvement' => true, 'available_resources' => ['Доска']];
        $snapshot = app(ContextSnapshotBuilder::class)->build($c['school'], $c['teacher'], $data, $c['event']);

        return ScenarioRequest::create(['school_id' => $c['school']->id, 'user_id' => $c['teacher']->id, ...$data, 'context_snapshot' => $snapshot]);
    }

    public function test_cultural_comparison_uses_live_claim_bound_evidence_and_effective_snapshot(): void
    {
        $c = $this->context();
        $request = $this->scenarioRequest($c);
        $this->assertArrayHasKey('effective_status', $request->context_snapshot['cultural_provenance']['claims'][0]);
        $service = app(ScenarioComparisonService::class);
        $pair = $service->createComparison($c['school'], $c['teacher'], $request, 'cultural_context', true);
        $this->assertTrue($pair->is_cultural_available);
        $this->assertCount(2, $pair->variant_b_payload['cultural_claims']);
        $this->assertSame('verified_authentic', $pair->variant_b_payload['cultural_claims'][0]['server_verification_status']);
        CulturalClaimSource::where('event_source_id', $c['source']->id)->update(['is_detached' => true]);
        $this->assertFalse($service->isCulturalAvailable($pair));
        $this->actingAs($c['teacher'])->withHeader('X-School-Id', $c['school']->id)->postJson('/api/v1/scenario-comparisons/'.$pair->id.'/selection', ['selected_variant' => 'B', 'selection_reason' => 'Attempt after revocation'])->assertConflict();
        $stale = $service->createComparison($c['school'], $c['teacher'], $request, 'cultural_context', true);
        $this->assertFalse($stale->is_cultural_available);
        $this->assertSame('unavailable_no_provenance', $stale->variant_b_payload['status']);
        $normal = $service->createComparison($c['school'], $c['teacher'], $request, 'universal_vs_personalized', true);
        $this->assertNotNull($normal->variant_a_scenario_id);
        $this->assertNotNull($normal->variant_b_scenario_id);
    }

    public function test_all_three_mock_scales_pass_strict_schema_and_semantic_validation(): void
    {
        $c = $this->context();
        foreach (['class_level', 'all_school', 'family_community'] as $scale) {
            $request = $this->scenarioRequest($c, $scale);
            $payload = app(MockLlmProvider::class)->generate($request)['output_payload'];
            app(GenerationOutputValidator::class)->validate($payload);
            app(ScenarioSemanticValidator::class)->validate($payload, $request->context_snapshot);
            $this->assertContains($payload['pedagogical_mechanism']['target_activity_title'], array_column($payload['activities'], 'title'));
        }
    }

    public function test_calendar_returns_real_annual_and_multi_day_occurrence_dates(): void
    {
        $c = $this->context();
        $occ = EventOccurrence::create(['calendar_event_id' => $c['event']->id, 'academic_year' => '2026-2027', 'start_date' => '2026-12-30', 'end_date' => '2027-01-02', 'source_reference' => 'FE012 test record']);
        $response = $this->actingAs($c['teacher'])->withHeader('X-School-Id', $c['school']->id)
            ->getJson('/api/v1/calendar-events?from=2027-01-01&to=2027-01-31&q=FE012')->assertOk();
        $event = collect($response->json('data'))->firstWhere('id', $c['event']->id);
        $this->assertNotNull($event);
        $this->assertSame('fixed_annual', $event['date_type']);
        $this->assertSame(12, $event['fixed_month']);
        $this->assertSame('2026-12-30', $event['occurrences'][0]['start_date']);
        $this->assertSame('2027-01-02', $event['occurrences'][0]['end_date']);
        $this->getJson('/api/v1/calendar-events/'.$c['event']->id)->assertOk()->assertJsonPath('data.occurrences.0.id', $occ->id);
    }

    public function test_tied_queue_pages_match_the_exact_deterministic_database_order(): void
    {
        $c = $this->context();
        for ($n = 0; $n < 13; $n++) {
            EventSource::create(['calendar_event_id' => $c['event']->id, 'source_type' => 'scientific_publication', 'title' => 'FE012 tied '.$n, 'created_at' => '2099-01-01 00:00:00+00']);
        }
        $expected = EventSource::where('created_at', '2099-01-01 00:00:00+00')->orderByDesc('created_at')->orderByDesc('id')->pluck('id')->all();
        $ids = [];
        for ($page = 1; $page <= 3; $page++) {
            $res = $this->actingAs($c['curator'])->getJson('/api/v1/catalog/review-queue?status=all&region_code=66&per_page=5&page='.$page)->assertOk();
            $ids = [...$ids, ...array_column($res->json('data'), 'id')];
        }
        $this->assertSame($expected, array_slice($ids, 0, 13));
        $this->assertSame(count($ids), count(array_unique($ids)));
    }

    public function test_pilot_comparison_validation_preserves_token_and_expert_versions_reload_with_rbac(): void
    {
        $c = $this->context();
        $request = $this->scenarioRequest($c);
        $pair = app(ScenarioComparisonService::class)->createComparison($c['school'], $c['teacher'], $request, 'universal_vs_personalized', true);
        $pilot = app(PilotService::class);
        $inv = $pilot->createParticipant($c['school'], ['subject_area' => 'Test', 'teaching_experience_years' => 1, 'is_synthetic' => true]);
        $body = ['invite_token' => $inv['invite_token'], 'scenario_id' => $pair->variant_a_scenario_id, 'prep_time_baseline_minutes' => 100, 'prep_time_ai_minutes' => 40, 'personalization_selected' => true, 'manual_edit_percentage' => 10, 'cultural_context_used' => false];
        $this->postJson('/api/v1/pilot/responses', [...$body, 'comparison_id' => '00000000-0000-4000-8000-000000000012'])->assertUnprocessable();
        $this->assertFalse($inv['participant']->fresh()->is_submitted);
        $pair->update(['status' => 'failed']);
        $this->postJson('/api/v1/pilot/responses', [...$body, 'comparison_id' => $pair->id])->assertUnprocessable();
        $this->assertFalse($inv['participant']->fresh()->is_submitted);
        $pair->update(['status' => 'completed']);
        $response = $this->postJson('/api/v1/pilot/responses', [...$body, 'comparison_id' => $pair->id])->assertCreated();
        $id = $response->json('data.id');
        $this->postJson('/api/v1/pilot/responses', $body)->assertConflict();
        $row = PilotResponse::findOrFail($id);
        foreach ([0, 1] as $errors) {
            $pilot->submitReviewFinding($row, $c['curator'], ['expert_audit_status' => 'audited', 'critical_cultural_errors' => $errors, 'methodological_remarks' => 'Version '.$errors]);
        }
        $this->actingAs($c['curator'])->getJson('/api/v1/pilot/responses/'.$id.'/reviews')->assertOk()->assertJsonPath('data.effective_review.finding_version', 2)->assertJsonCount(2, 'data.versions');
        $this->actingAs($c['curator'])->getJson('/api/v1/pilot/responses/'.$id)->assertOk();
        $other = User::factory()->curator('regional', ['77'])->create();
        $this->actingAs($other)->getJson('/api/v1/pilot/responses/'.$id.'/reviews')->assertForbidden();
        $this->actingAs($c['teacher'])->getJson('/api/v1/pilot/responses/'.$id.'/reviews')->assertForbidden();
        SchoolMembership::where('user_id',$c['teacher']->id)->update(['role' => 'school_admin']);
        $this->actingAs($c['teacher'])->withHeader('X-School-Id',$c['school']->id)->getJson('/api/v1/pilot/participants')->assertOk()->assertJsonFragment(['response_id' => $id]);
    }
}
