<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScenarioComparison extends Model
{
    use HasFactory, HasUuid7;

    protected $table = 'scenario_comparisons';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'school_id',
        'user_id',
        'scenario_request_id',
        'comparison_type',
        'status',
        'variant_a_scenario_id',
        'variant_a_revision_id',
        'variant_a_payload',
        'variant_b_scenario_id',
        'variant_b_revision_id',
        'variant_b_payload',
        'differences_summary',
        'is_cultural_available',
        'selected_variant',
        'selection_reason',
        'cultural_usefulness_rating',
        'selection_recorded_at',
        'selected_by_user_id',
        'is_synthetic',
    ];

    protected function casts(): array
    {
        return [
            'variant_a_payload' => 'array',
            'variant_b_payload' => 'array',
            'differences_summary' => 'array',
            'is_cultural_available' => 'boolean',
            'cultural_usefulness_rating' => 'integer',
            'is_synthetic' => 'boolean',
            'selection_recorded_at' => 'datetime',
        ];
    }

    public function scopeReal($query)
    {
        return $query->where('is_synthetic', false);
    }

    public function selectedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'selected_by_user_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class, 'school_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scenarioRequest(): BelongsTo
    {
        return $this->belongsTo(ScenarioRequest::class, 'scenario_request_id');
    }

    public function variantAScenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class, 'variant_a_scenario_id');
    }

    public function variantBScenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class, 'variant_b_scenario_id');
    }
}
