<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ScenarioRequest extends Model
{
    use HasFactory, HasUuid7;

    protected $table = 'scenario_requests';

    public $timestamps = false;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'school_id',
        'user_id',
        'calendar_event_id',
        'tradition_version_id',
        'custom_event_title',
        'scale',
        'format_type',
        'target_grades',
        'participants_count',
        'duration_minutes',
        'value_direction',
        'family_involvement',
        'available_resources',
        'pedagogical_constraints',
        'context_snapshot',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'target_grades' => 'array',
            'available_resources' => 'array',
            'context_snapshot' => 'array',
            'family_involvement' => 'boolean',
            'participants_count' => 'integer',
            'duration_minutes' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class, 'school_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function calendarEvent(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'calendar_event_id');
    }

    public function traditionVersion(): BelongsTo
    {
        return $this->belongsTo(TraditionVersion::class, 'tradition_version_id');
    }

    public function scenario(): HasOne
    {
        return $this->hasOne(Scenario::class, 'scenario_request_id');
    }

    public function generationRuns(): HasMany
    {
        return $this->hasMany(GenerationRun::class, 'scenario_request_id');
    }
}
