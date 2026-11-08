<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExportedArtifact extends Model
{
    use HasFactory, HasUuid7;

    protected $table = 'exported_artifacts';

    public $timestamps = false;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'export_job_id',
        'file_disk',
        'file_path',
        'file_name',
        'file_size_bytes',
        'mime_type',
        'sha256_hash',
        'provenance_fingerprint',
        'expires_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'file_size_bytes' => 'integer',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function exportJob(): BelongsTo
    {
        return $this->belongsTo(ExportJob::class, 'export_job_id');
    }
}
