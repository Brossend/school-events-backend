<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GenerationRun extends Model
{
    use HasFactory, HasUuid7;

    protected $table = 'generation_runs';

    public $timestamps = false;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'scenario_request_id',
        'provider_name',
        'model_identifier',
        'prompt_version',
        'schema_version',
        'status',
        'attempts',
        'latency_ms',
        'prompt_tokens',
        'completion_tokens',
        'error_code',
        'error_message',
        'sanitized_prompt',
        'sanitized_response',
        'created_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'latency_ms' => 'integer',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'sanitized_response' => 'array',
            'created_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function scenarioRequest(): BelongsTo
    {
        return $this->belongsTo(ScenarioRequest::class, 'scenario_request_id');
    }

    public function revision(): HasOne
    {
        return $this->hasOne(ScenarioRevision::class, 'source_generation_run_id');
    }
}
