<?php

declare(strict_types=1);

namespace App\Services\Llm;

use RuntimeException;

class LlmProviderException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $safeMessage, public readonly array $metadata = [])
    {
        parent::__construct($safeMessage);
    }
}
