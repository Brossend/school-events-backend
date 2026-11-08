<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScenarioStage extends Model
{
    use HasFactory, HasUuid7;

    protected $table = 'scenario_stages';

    public $timestamps = false;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'revision_id',
        'order_index',
        'stage_identifier',
        'title',
        'duration_minutes',
        'stage_type',
        'teacher_actions',
        'student_actions',
        'dialogue_prompts',
        'teacher_tips',
        'transition_text',
    ];

    protected function casts(): array
    {
        return [
            'order_index' => 'integer',
            'duration_minutes' => 'integer',
            'dialogue_prompts' => 'array',
        ];
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(ScenarioRevision::class, 'revision_id');
    }
}
