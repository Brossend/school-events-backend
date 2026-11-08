<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VerificationRun extends Model
{
    use HasFactory, HasUuid7;

    protected $table = 'verification_runs';

    public $timestamps = false;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'revision_id',
        'rule_engine_version',
        'status',
        'p0_violations_count',
        'p1_violations_count',
        'execution_time_ms',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'p0_violations_count' => 'integer',
            'p1_violations_count' => 'integer',
            'execution_time_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('VerificationRun is strictly append-only and immutable. Overwriting runs is prohibited.');
        });

        static::deleting(function () {
            throw new \LogicException('VerificationRun is strictly append-only and immutable. Deleting runs is prohibited.');
        });
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(ScenarioRevision::class, 'revision_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(VerificationFinding::class, 'verification_run_id')->orderBy('created_at');
    }
}
