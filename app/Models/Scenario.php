<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Scenario extends Model
{
    use HasFactory, HasUuid7, SoftDeletes;

    protected $table = 'scenarios';

    public $timestamps = false;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'school_id',
        'user_id',
        'scenario_request_id',
        'title',
        'current_revision_id',
        'optimistic_lock_version',
        'is_archived',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'optimistic_lock_version' => 'integer',
            'is_archived' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
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

    public function request(): BelongsTo
    {
        return $this->belongsTo(ScenarioRequest::class, 'scenario_request_id');
    }

    public function scenarioRequest(): BelongsTo
    {
        return $this->belongsTo(ScenarioRequest::class, 'scenario_request_id');
    }

    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(ScenarioRevision::class, 'current_revision_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ScenarioRevision::class, 'scenario_id');
    }
}
