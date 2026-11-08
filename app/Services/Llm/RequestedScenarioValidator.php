<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Models\ScenarioRequest;
use Illuminate\Validation\ValidationException;

class RequestedScenarioValidator
{
    public function validate(array $payload, ScenarioRequest $request, string $mode): void
    {
        $errors = [];
        foreach (['scale', 'format_type'] as $key) {
            if (($payload['format'][$key] ?? null) !== $request->$key) {
                $errors['RULE_REQUEST_'.$key] = 'Результат изменил заданный формат или масштаб.';
            }
        }
        if (($payload['value_direction'] ?? null) !== $request->value_direction) {
            $errors['RULE_REQUEST_DIRECTION'] = 'Результат изменил ценностное направление.';
        }
        $actual = $payload['target_audience']['target_grades'] ?? [];
        $expected = $request->target_grades;
        sort($actual);
        sort($expected);
        if ($actual !== $expected || ($payload['target_audience']['family_involvement'] ?? null) !== (bool) $request->family_involvement) {
            $errors['RULE_REQUEST_AUDIENCE'] = 'Результат изменил классы или участие семьи.';
        }
        $age = max($expected) <= 4 ? 'primary_1_4' : (min($expected) >= 5 && max($expected) <= 9 ? 'basic_5_9' : (min($expected) >= 10 ? 'senior_10_11' : 'mixed'));
        if (($payload['target_audience']['age_group'] ?? null) !== $age
            || ($payload['target_audience']['participants_count_min'] ?? PHP_INT_MAX) > $request->participants_count
            || ($payload['target_audience']['participants_count_max'] ?? 0) < $request->participants_count) {
            $errors['RULE_REQUEST_PARTICIPANTS'] = 'Результат не соответствует возрасту или количеству участников.';
        }
        if (in_array($mode, ['universal', 'without_cultural_context'], true) && ! empty($payload['cultural_claims'])) {
            $errors['RULE_MODE_CULTURAL_CLAIMS'] = 'Вариант без культурного контекста не может содержать культурные утверждения.';
        }
        if ($mode === 'with_cultural_context' || ! empty($payload['cultural_claims'])) {
            $trusted = app(TrustedGenerationContext::class)->forRequest($request)['cultural_provenance']['claims'] ?? [];
            if ($mode === 'with_cultural_context' && ! $trusted) {
                throw new LlmProviderException('LLM_PROVENANCE_CHANGED', 'Подтверждённый культурный контекст больше недоступен.');
            }
            if ($mode === 'with_cultural_context' && empty($payload['cultural_claims'])) {
                $errors['RULE_TRUSTED_CLAIM_MISSING'] = 'Культурный вариант должен дословно включать предоставленное trusted_claim с его источником.';
            }
            $canonical = fn ($text) => mb_strtolower(preg_replace('/\s+/u', ' ', trim($text)) ?? '', 'UTF-8');
            foreach ($payload['cultural_claims'] as $claim) {
                $match = collect($trusted)->first(fn ($t) => in_array($claim['claim_id'], [$t['id'], 'claim_'.str_replace('-', '', $t['id']), 'claim_'.substr(md5($t['claim_text']), 0, 10)], true)
                    && $canonical($claim['claim_text']) === $canonical($t['claim_text']));
                if (! $match) {
                    $errors['RULE_TRUSTED_CLAIM_MATCH'] = 'Не добавляйте и не перефразируйте факты: скопируйте claim_id и точный claim_text из trusted_claims.';

                    continue;
                }
                foreach ($claim['source_references'] as $reference) {
                    $source = collect($match['evidence_records'])->first(fn ($ev) => $canonical($reference['source_title']) === $canonical($ev['source_title']) && $canonical($reference['citation_locator']) === $canonical($ev['citation_locator']));
                    if (! $source || (! empty($reference['url_or_archive_ref']) && $reference['url_or_archive_ref'] !== ($source['url'] ?? ''))) {
                        $errors['RULE_TRUSTED_REFERENCE_MATCH'] = 'Source title, citation_locator и URL копируются дословно из предоставленных источников данного утверждения.';
                    }
                }
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
