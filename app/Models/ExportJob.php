<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ExportJob extends Model
{
    use HasFactory, HasUuid7;

    protected $table = 'export_jobs';

    public $timestamps = false;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'revision_id',
        'user_id',
        'format',
        'status',
        'provenance_fingerprint',
        'error_message',
        'created_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(ScenarioRevision::class, 'revision_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function artifact(): HasOne
    {
        return $this->hasOne(ExportedArtifact::class, 'export_job_id');
    }
}
