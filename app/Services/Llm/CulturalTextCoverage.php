<?php

declare(strict_types=1);

namespace App\Services\Llm;

/** Full-tree traversal; conservative coverage, not a proof of semantic entailment. */
class CulturalTextCoverage
{
    public const VERSION = 'cultural-text-1.0.0';

    public function assess(array $strings, array $trusted): array
    {
        $canonical = fn ($s) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s), " \n\r\t.!?"), 'UTF-8');
        $allowed = [];
        $bibliography = [];
        foreach ($trusted as $claim) {
            $evidence = array_values(array_filter($claim['evidence_records'] ?? [], fn ($ev) => ($ev['verification_status'] ?? '') === 'verified_authentic' && ! empty($ev['event_source_id'])));
            if (! $evidence) {
                continue;
            }
            $allowed[$canonical($claim['claim_text'])] = ['claim_id' => $claim['id'], 'evidence_records' => array_map(fn ($e) => array_intersect_key($e, array_flip(['event_source_id', 'source_title', 'citation_locator', 'verified_at'])), $evidence)];
            foreach ($evidence as $ev) {
                foreach (['source_title', 'citation_locator', 'url', 'author'] as $key) {
                    if (! empty($ev[$key])) {
                        $bibliography[] = $canonical($ev[$key]);
                    }
                }
            }
        }
        $findings = [];
        $supported = [];
        foreach ($strings as $path => $text) {
            // Server normalization adds typed evidence timestamps. They are
            // metadata, not a narrative claim; prose in the same field is not exempt.
            if (preg_match('/^\$\.cultural_claims\.\d+\.evidence_records\.\d+\.verified_at$/', $path)
                && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $text)) {
                continue;
            }

            // A matching source title is bibliography, not a new certified proposition.
            if (in_array($canonical($text), $bibliography, true)) {
                continue;
            }
            foreach (preg_split('/(?<=[.!?])\s+|\R/u', $text) as $sentence) {
                if (isset($allowed[$canonical($sentence)])) {
                    $supported[] = ['path' => $path, 'text' => $sentence, ...$allowed[$canonical($sentence)], 'status' => 'exact_supported_claim'];

                    continue;
                }
                if (preg_match('/\b(?:1[0-9]{3}|20[0-9]{2})\b|(?:[1-9]|[12]\d|3[01])\s+(?:январ|феврал|март|апрел|мая|июн|июл|август|сентябр|октябр|ноябр|декабр)|Минин|Пожарск|ополчен|интервент|летопис|архив|в\s+[IVX]+\s*век|историческ|традици|обыча|обряд|народн\p{L}*\s+праздник|праздник\p{L}*\s+(?:установлен|отмеча|посвящ)|освободи\p{L}*\s+Москв|издревле|предки|с\s+древн/ui', $sentence)) {
                    $explicit = preg_match('/\b(?:1[0-9]{3}|20[0-9]{2})\b|Минин|Пожарск|ополчен|интервент/ui', $sentence);
                    $findings[] = ['code' => 'UNSUPPORTED_CULTURAL_TEXT', 'path' => $path, 'text' => $sentence,
                        'message' => 'Этот фрагмент не совпадает с предоставленным подтверждённым утверждением. Исправьте, удалите или передайте на ручную проверку; дата праздника не подтверждает его историю.',
                        'severity' => $explicit ? 'error' : 'review'];
                }
            }
        }

        return ['version' => self::VERSION, 'status' => 'requires_review', 'complete' => false,
            'scanned_string_count' => count($strings), 'supported_assertions' => $supported, 'findings' => $findings,
            'whole_document_verified' => false, 'method' => 'recursive_all_string_leaves_exact_live_claim_matching_and_cue_rules',
            'limitation' => 'Словарные правила могут пропускать перефразы и факты без дат/имён. Пустой cultural_claims и отсутствие находок не означают культурный PASS.'];
    }
}
