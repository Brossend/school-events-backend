<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherSignoff extends Model
{
    use HasFactory, HasUuid7;

    protected $table = 'teacher_signoffs';

    public $timestamps = false;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'revision_id',
        'user_id',
        'is_confirmed',
        'provenance_fingerprint',
        'checklist_confirmed',
        'teacher_notes',
        'ip_address',
        'confirmed_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'is_confirmed' => 'boolean',
            'checklist_confirmed' => 'array',
            'confirmed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('TeacherSignoff is strictly append-only and immutable. Overwriting signoffs is prohibited.');
        });

        static::deleting(function () {
            throw new \LogicException('TeacherSignoff is strictly append-only and immutable. Deleting signoffs is prohibited.');
        });
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(ScenarioRevision::class, 'revision_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
