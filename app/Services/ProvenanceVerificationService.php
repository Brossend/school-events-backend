<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CulturalClaim;
use App\Models\CulturalClaimSource;
use App\Models\EventSource;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ProvenanceVerificationService
{
    /**
     * Attach and verify an event source for a cultural claim.
     * Enforces Level 1 Source Provenance and Level 2 Human Claim-to-Evidence Attestation.
     *
     * @throws ValidationException
     */
    public function verifyClaim(
        CulturalClaim $claim,
        EventSource $source,
        string $citationLocator,
        User $verifier,
        ?string $evidenceExcerpt = null,
        ?string $academicYear = null,
        ?string $notes = null
    ): CulturalClaimSource {
        // Positional argument swap if called as ($claim, $source, $locator, $verifier, $academicYear, $evidenceExcerpt, $notes)
        if ($evidenceExcerpt !== null && preg_match('/^\d{4}-\d{4}$/', $evidenceExcerpt) && $academicYear !== null && ! preg_match('/^\d{4}-\d{4}$/', $academicYear)) {
            $temp = $evidenceExcerpt;
            $evidenceExcerpt = $academicYear;
            $academicYear = $temp;
        }

        $citationLocator = trim($citationLocator);

        // 1. Mandatory and specific citation locator
        if ($citationLocator === '' || mb_strlen($citationLocator) < 3 || $this->isGenericPlaceholder($citationLocator)) {
            throw ValidationException::withMessages([
                'citation_locator' => 'Для подтверждения факта обязателен точный проверяемый локатор цитаты (статья, пункт, страница или архивный лист).',
            ]);
        }

        // 2. Mandatory concrete evidence excerpt (Strictly separated from notes, no fallback)
        if ($evidenceExcerpt === null || trim($evidenceExcerpt) === '') {
            throw ValidationException::withMessages([
                'evidence_excerpt' => 'Для подтверждения факта обязательна конкретная выдержка или цитата из текста источника.',
            ]);
        }

        $evidenceExcerpt = trim($evidenceExcerpt);
        if (mb_strlen($evidenceExcerpt) < 10 || $this->isGenericPlaceholder($evidenceExcerpt)) {
            throw ValidationException::withMessages([
                'evidence_excerpt' => 'Выдержка из источника должна содержать содержательный проверяемый фрагмент текста (не менее 10 символов) и не может быть заполнителем.',
            ]);
        }

        // 3. Cross-event source injection check (Claim and Source must belong to the exact same calendar event)
        if ($claim->calendar_event_id !== $source->calendar_event_id) {
            throw ValidationException::withMessages([
                'event_source_id' => 'Источник принадлежит другому календарному событию. Межсобытийное связывание источников запрещено.',
            ]);
        }

        // 4. Source Typology vs Claim Content Check
        // Normative legal acts can only confirm legal/calendar dates and official names, not historical/ethnographic claims
        if ($source->source_type === 'normative_act' && $this->isHistoricalOrEthnographicClaim($claim->claim_text)) {
            throw ValidationException::withMessages([
                'event_source_id' => 'Нормативный правовой акт не может подтверждать исторические события или этнографические традиции. Для исторических сведений требуется научный первоисточник или академическая публикация.',
            ]);
        }

        // 5. Verifier authorization: must be active catalog_curator or system_admin
        if (! $verifier->is_active || (! $verifier->isCurator() && ! $verifier->isSystemAdmin())) {
            throw ValidationException::withMessages([
                'verified_by_user_id' => 'Только активный куратор каталога или системный администратор имеют право верифицировать культурные утверждения.',
            ]);
        }

        // 6. Regional curator scope check for verifier
        $claimEvent = $claim->event;
        if ($verifier->isCurator() && $verifier->curator_scope === 'regional') {
            if ($claimEvent?->event_type === 'federal' || $claimEvent?->region_code === null) {
                throw ValidationException::withMessages([
                    'verifier' => 'Региональный куратор не имеет полномочий верифицировать утверждения федеральных событий.',
                ]);
            }

            $allowedRegions = $verifier->curated_region_codes ?? [];
            if (! in_array($claimEvent->region_code, $allowedRegions, true)) {
                throw ValidationException::withMessages([
                    'verifier' => "Куратор региона не имеет полномочий для региона '{$claimEvent->region_code}'.",
                ]);
            }
        }

        // 7. Source review check: source MUST have an approved review with deterministic tie-breaker
        $latestReview = $source->reviews()
            ->orderBy('reviewed_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if (! $latestReview) {
            throw ValidationException::withMessages([
                'event_source_id' => 'Для источника отсутствует запись экспертной рецензии (source_reviews). Источник не проверен.',
            ]);
        }

        if ($latestReview->decision !== 'approved') {
            throw ValidationException::withMessages([
                'event_source_id' => "Последнее решение по источнику — '{$latestReview->decision}'. Источник не может использоваться для подтверждения подлинности утверждений.",
            ]);
        }

        // Check reviewer credentials of the review that approved the source
        $reviewer = $latestReview->reviewer;
        if (! $reviewer || ! $reviewer->is_active || (! $reviewer->isCurator() && ! $reviewer->isSystemAdmin())) {
            throw ValidationException::withMessages([
                'reviewer' => 'Эксперт, рецензировавший источник, не является активным куратором или системным администратором.',
            ]);
        }

        if ($reviewer->isCurator() && $reviewer->curator_scope === 'regional') {
            if ($claimEvent?->event_type === 'federal' || $claimEvent?->region_code === null) {
                throw ValidationException::withMessages([
                    'reviewer' => 'Куратор региона не имеет полномочий одобрять источники для федеральных событий.',
                ]);
            }

            $allowedReviewerRegions = $reviewer->curated_region_codes ?? [];
            if (! in_array($claimEvent->region_code, $allowedReviewerRegions, true)) {
                throw ValidationException::withMessages([
                    'reviewer' => "Куратор региона не имеет полномочий для региона события '{$claimEvent->region_code}'.",
                ]);
            }
        }

        // 8. Academic year format check if provided (must be consecutive years YYYY-YYYY)
        if ($academicYear !== null) {
            if (! preg_match('/^(\d{4})-(\d{4})$/', $academicYear, $matches) || ((int) $matches[2] !== (int) $matches[1] + 1)) {
                throw ValidationException::withMessages([
                    'applicable_academic_year' => 'Учебный год должен быть последовательным периодом формата YYYY-YYYY (например, 2026-2027).',
                ]);
            }
        }

        $cleanNotes = ($notes !== null && trim($notes) !== '') ? trim($notes) : null;

        // 9. Level 2: Record explicit claim-to-evidence attestation with separated excerpt and notes
        return CulturalClaimSource::updateOrCreate(
            [
                'cultural_claim_id' => $claim->id,
                'event_source_id' => $source->id,
                'citation_locator' => $citationLocator,
            ],
            [
                'calendar_event_id' => $claim->calendar_event_id,
                'citation_excerpt' => $evidenceExcerpt,
                'verification_status' => 'verified_authentic',
                'verified_by_user_id' => $verifier->id,
                'verified_at' => now(),
                'applicable_academic_year' => $academicYear,
                'notes' => $cleanNotes,
            ]
        );
    }

    /**
     * Dynamically evaluate the effective verification status of a cultural claim source.
     * Prevents false verification: requires both source approval AND valid claim-to-evidence human attestation.
     */
    public function evaluateStatus(CulturalClaimSource $claimSource): string
    {
        // Explicitly rejected or disputed claims remain so
        if (in_array($claimSource->verification_status, ['disputed', 'rejected_fake'], true)) {
            return $claimSource->verification_status;
        }

        // Level 2: Human claim-to-evidence attestation is mandatory
        // Having an approved source review alone does not replace human attestation.
        // Fabricated excerpt or unreviewed claim without human verifier cannot be elevated.
        if (! $claimSource->verified_by_user_id || ! $claimSource->verified_at) {
            return 'unverified';
        }

        // Must have concrete, non-empty, non-placeholder citation locator
        if (empty($claimSource->citation_locator) || $this->isGenericPlaceholder($claimSource->citation_locator)) {
            return 'unverified';
        }

        // Must have concrete, non-empty, non-placeholder citation excerpt (checked separately from notes)
        if (empty($claimSource->citation_excerpt) || mb_strlen(trim($claimSource->citation_excerpt)) < 10 || $this->isGenericPlaceholder($claimSource->citation_excerpt)) {
            return 'unverified';
        }

        // Level 1: Source Provenance check
        $source = $claimSource->source;
        if (! $source || $source->calendar_event_id !== $claimSource->calendar_event_id) {
            return 'unverified';
        }

        // Source typology boundary: normative act cannot verify historical claims
        $claim = $claimSource->claim;
        if ($source->source_type === 'normative_act' && $claim && $this->isHistoricalOrEthnographicClaim($claim->claim_text)) {
            return 'unverified';
        }

        // Deterministic review lookup: reviewed_at DESC, id DESC
        $latestReview = $source->reviews()
            ->orderBy('reviewed_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if (! $latestReview) {
            return 'unverified';
        }

        if ($latestReview->decision === 'rejected') {
            return 'rejected_fake';
        }

        if ($latestReview->decision === 'requires_amendment') {
            return 'unverified';
        }

        if ($latestReview->decision !== 'approved') {
            return 'unverified';
        }

        // Verify reviewer credentials
        $reviewer = $latestReview->reviewer;
        if (! $reviewer || ! $reviewer->is_active || (! $reviewer->isCurator() && ! $reviewer->isSystemAdmin())) {
            return 'unverified';
        }

        $event = $claim?->event;
        if ($reviewer->isCurator() && $reviewer->curator_scope === 'regional' && $event) {
            if ($event->event_type === 'federal' || ! in_array($event->region_code, $reviewer->curated_region_codes ?? [], true)) {
                return 'unverified';
            }
        }

        // Verify verifier credentials
        $verifier = $claimSource->verifiedBy;
        if (! $verifier || ! $verifier->is_active || (! $verifier->isCurator() && ! $verifier->isSystemAdmin())) {
            return 'unverified';
        }

        if ($verifier->isCurator() && $verifier->curator_scope === 'regional' && $event) {
            if ($event->event_type === 'federal' || ! in_array($event->region_code, $verifier->curated_region_codes ?? [], true)) {
                return 'unverified';
            }
        }

        return $claimSource->verification_status;
    }

    /**
     * Check if text is a meaningless generic placeholder.
     */
    protected function isGenericPlaceholder(string $text): bool
    {
        $normalized = mb_strtolower(trim($text));
        $placeholders = [
            'см. источник',
            'в тексте',
            'везде',
            'весь текст',
            'весь закон',
            'весь указ',
            'общий',
            'нет',
            'n/a',
            'none',
            'test',
            'placeholder',
            'цитата',
            'выдержка',
        ];

        return in_array($normalized, $placeholders, true);
    }

    /**
     * Detect whether a claim asserts historical events or ethnographic traditions
     * that cannot be confirmed solely by a modern normative legal act.
     */
    public function isHistoricalOrEthnographicClaim(string $claimText): bool
    {
        // 1. Matches pre-modern years (prior to 1900, e.g. 1612, 1724, 1812, etc.)
        if (preg_match('/\b(1[0-8]\d{2})\b/', $claimText)) {
            return true;
        }

        // 2. Matches specific historical figures and events
        $historicalKeywords = [
            'минин',
            'пожарск',
            'ополчен',
            'освобожден',
            'петр i',
            'петр первый',
            'петра i',
            'петра первого',
            'сенат',
            'обряд',
            'этнографич',
            'век',
            'столети',
            'бажов',
            'традици',
            'народност',
        ];

        $lower = mb_strtolower($claimText);
        foreach ($historicalKeywords as $keyword) {
            if (mb_strpos($lower, $keyword) !== false) {
                // If it mentions establishment of holiday in modern Russia, distinguish
                if (mb_strpos($lower, 'установлен в память') !== false || mb_strpos($lower, 'в память об') !== false) {
                    return true;
                }
                if (in_array($keyword, ['минин', 'пожарск', 'ополчен', 'петр i', 'петра i', 'обряд', 'этнографич'], true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
