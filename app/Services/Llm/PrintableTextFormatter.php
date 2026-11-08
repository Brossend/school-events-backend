<?php

declare(strict_types=1);

namespace App\Services\Llm;

/** Narrow, lossless repair of escaped line endings in printable text only. */
class PrintableTextFormatter
{
    public function repair(array $payload): array
    {
        $changes = [];
        $walk = function (array $node, string $path) use (&$walk, &$changes): array {
            foreach ($node as $key => $value) {
                $at = $path.'.'.$key;
                if (is_array($value)) {
                    $node[$key] = $walk($value, $at);

                    continue;
                }
                if (! is_string($value) || ! preg_match('/^\$\.materials\.(handouts|printable_cards|presentation_slides)\./', $at)) {
                    continue;
                }
                // JSON has already been parsed; these are literal backslashes.
                $fixed = preg_replace_callback('/\\\\+[nr]/', fn ($m) => str_ends_with($m[0], 'n') ? "\n" : "\r", $value);
                // Numeric pair punctuation only; do not alter URLs or prose escapes.
                $fixed = preg_replace('/(?<=\d)\\\\+\/(?=\d)/', '/', $fixed);
                if ($fixed !== $value) {
                    $changes[] = ['path' => $at, 'before' => $value, 'after' => $fixed, 'operation' => 'printable_escape_to_character'];
                    $node[$key] = $fixed;
                }
            }

            return $node;
        };

        return ['payload' => $walk($payload, '$'), 'changes' => $changes];
    }
}
