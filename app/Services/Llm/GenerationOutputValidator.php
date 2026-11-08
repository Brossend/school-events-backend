<?php

declare(strict_types=1);

namespace App\Services\Llm;

use Illuminate\Validation\ValidationException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;

class GenerationOutputValidator
{
    protected Validator $validator;
    protected string $schemaId = 'https://school-events.sber-urfu.ru/schemas/v1/generation-output.schema.json';

    public function __construct()
    {
        $this->validator = new Validator();
        $this->validator->resolver()->registerFile(
            $this->schemaId,
            base_path('docs/generation-output.schema.json')
        );
    }

    /**
     * Validate raw LLM output against Draft 2020-12 JSON Schema.
     *
     * @throws ValidationException
     */
    public function validate(array $data): void
    {
        // LLM output must never declare verification statuses
        if (isset($data['verification_status']) || isset($data['server_verification_status'])) {
            throw ValidationException::withMessages([
                'verification_status' => 'LLM output must not declare verification statuses.',
            ]);
        }

        // Convert array to object structure for Opis JSON Schema validator
        $dataObject = Helper::toJSON($data);
        $result = $this->validator->validate($dataObject, $this->schemaId);

        if (! $result->isValid()) {
            $error = $result->error();
            $formatter = new ErrorFormatter();
            $formatted = $formatter->format($error);

            throw ValidationException::withMessages([
                'generation_schema' => 'LLM output does not conform to Draft 2020-12 JSON Schema: ' . json_encode($formatted, JSON_UNESCAPED_UNICODE),
            ]);
        }
    }
}
