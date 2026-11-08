<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CulturalClaimEvidenceAuditEvent extends Model
{
    use HasFactory, HasUuid7;

    protected $table = 'cultural_claim_evidence_audit_events';

    public $timestamps = false;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'cultural_claim_source_id',
        'cultural_claim_id',
        'event_source_id',
        'action',
        'actor_user_id',
        'previous_verification_status',
        'new_verification_status',
        'citation_locator',
        'citation_excerpt',
        'reason',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(CulturalClaim::class, 'cultural_claim_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(EventSource::class, 'event_source_id');
    }

    public function claimSource(): BelongsTo
    {
        return $this->belongsTo(CulturalClaimSource::class, 'cultural_claim_source_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
