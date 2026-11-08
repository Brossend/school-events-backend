<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PilotReviewFinding extends Model
{
    use HasFactory, HasUuid7;

    protected $table = 'pilot_review_findings';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'pilot_response_id',
        'expert_user_id',
        'expert_name',
        'expert_audit_status',
        'critical_cultural_errors',
        'methodological_remarks',
        'reviewed_at',
        'finding_version',
        'is_effective',
    ];

    protected function casts(): array
    {
        return [
            'critical_cultural_errors' => 'integer',
            'finding_version' => 'integer',
            'is_effective' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    public function scopeEffective($query)
    {
        return $query->where('is_effective', true);
    }

    public function response(): BelongsTo
    {
        return $this->belongsTo(PilotResponse::class, 'pilot_response_id');
    }

    public function expert(): BelongsTo
    {
        return $this->belongsTo(User::class, 'expert_user_id');
    }
}
