<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PilotResponse extends Model
{
    use HasFactory, HasUuid7;

    protected $table = 'pilot_responses';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'participant_id',
        'scenario_id',
        'prep_time_baseline_minutes',
        'prep_time_ai_minutes',
        'relative_time_saving',
        'personalization_selected',
        'manual_edit_percentage',
        'cultural_context_used',
        'cultural_usefulness_score',
        'feedback_text',
        'submitted_at',
        'comparison_id',
        'is_synthetic',
    ];

    protected function casts(): array
    {
        return [
            'prep_time_baseline_minutes' => 'integer',
            'prep_time_ai_minutes' => 'integer',
            'relative_time_saving' => 'float',
            'personalization_selected' => 'boolean',
            'manual_edit_percentage' => 'float',
            'cultural_context_used' => 'boolean',
            'cultural_usefulness_score' => 'integer',
            'is_synthetic' => 'boolean',
            'submitted_at' => 'datetime',
        ];
    }

    public function scopeReal($query)
    {
        return $query->where('is_synthetic', false);
    }

    public function comparison(): BelongsTo
    {
        return $this->belongsTo(ScenarioComparison::class, 'comparison_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(PilotParticipant::class, 'participant_id');
    }

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class, 'scenario_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(PilotReviewFinding::class, 'pilot_response_id')->orderByDesc('created_at');
    }

    public function latestReview(): HasOne
    {
        return $this->hasOne(PilotReviewFinding::class, 'pilot_response_id')->orderByDesc('created_at');
    }
}
