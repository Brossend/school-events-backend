<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ScenarioRevision extends Model
{
    use HasFactory, HasUuid7;

    protected $table = 'scenario_revisions';

    public $timestamps = false;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'scenario_id',
        'version_number',
        'source_generation_run_id',
        'created_by_user_id',
        'content_payload',
        'concept',
        'pedagogical_goal',
        'pedagogical_mechanism',
        'pedagogical_tasks',
        'expected_outcomes',
        'target_audience',
        'format',
        'total_duration_minutes',
        'teacher_instructions',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'content_payload' => 'array',
            'pedagogical_mechanism' => 'array',
            'pedagogical_tasks' => 'array',
            'expected_outcomes' => 'array',
            'target_audience' => 'array',
            'format' => 'array',
            'total_duration_minutes' => 'integer',
            'teacher_instructions' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('ScenarioRevision is strictly append-only and immutable. Overwriting revisions is prohibited.');
        });

        static::deleting(function () {
            throw new \LogicException('ScenarioRevision is strictly append-only and immutable. Deleting revisions is prohibited.');
        });
    }

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class, 'scenario_id');
    }

    public function sourceGenerationRun(): BelongsTo
    {
        return $this->belongsTo(GenerationRun::class, 'source_generation_run_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function stages(): HasMany
    {
        return $this->hasMany(ScenarioStage::class, 'revision_id')->orderBy('order_index');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(ScenarioMaterial::class, 'revision_id')->orderBy('order_index');
    }

    public function moderationState(): HasOne
    {
        return $this->hasOne(RevisionModerationState::class, 'revision_id');
    }

    public function verificationRuns(): HasMany
    {
        return $this->hasMany(VerificationRun::class, 'revision_id')->orderBy('created_at', 'desc');
    }

    public function teacherSignoffs(): HasMany
    {
        return $this->hasMany(TeacherSignoff::class, 'revision_id')->orderBy('confirmed_at', 'desc');
    }

    public function exportJobs(): HasMany
    {
        return $this->hasMany(ExportJob::class, 'revision_id')->orderBy('created_at', 'desc');
    }
}
