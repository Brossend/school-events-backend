<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RevisionModerationState extends Model
{
    use HasFactory;

    protected $table = 'revision_moderation_states';

    public $timestamps = false;

    protected $primaryKey = 'revision_id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'revision_id',
        'status',
        'provenance_fingerprint',
        'p0_violations_count',
        'p1_violations_count',
        'latest_verification_run_id',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'p0_violations_count' => 'integer',
            'p1_violations_count' => 'integer',
            'updated_at' => 'datetime',
        ];
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(ScenarioRevision::class, 'revision_id');
    }

    public function latestVerificationRun(): BelongsTo
    {
        return $this->belongsTo(VerificationRun::class, 'latest_verification_run_id');
    }
}
