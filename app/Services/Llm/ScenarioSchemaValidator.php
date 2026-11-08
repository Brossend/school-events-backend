<?php

declare(strict_types=1);

namespace App\Services\Llm;

use Illuminate\Validation\ValidationException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;

class ScenarioSchemaValidator
{
    protected Validator $validator;
    protected string $schemaId = 'https://school-events.sber-urfu.ru/schemas/v1/scenario.schema.json';

    public function __construct()
    {
        $this->validator = new Validator();
        $this->validator->resolver()->registerFile(
            $this->schemaId,
            base_path('docs/scenario.schema.json')
        );
    }

    /**
     * Validate scenario payload against docs/scenario.schema.json.
     *
     * @throws ValidationException
     */
    public function validate(array $data): void
    {
        $dataObject = Helper::toJSON($data);
        $result = $this->validator->validate($dataObject, $this->schemaId);

        if (! $result->isValid()) {
            $error = $result->error();
            $formatter = new ErrorFormatter();
            $formatted = $formatter->format($error);

            throw ValidationException::withMessages([
                'scenario_schema' => 'Scenario payload does not conform to docs/scenario.schema.json: ' . json_encode($formatted, JSON_UNESCAPED_UNICODE),
            ]);
        }
    }
}
