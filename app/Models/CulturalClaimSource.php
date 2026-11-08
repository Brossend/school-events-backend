<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CulturalClaimSource extends Model
{
    use HasFactory, HasUuid7;

    protected $table = 'cultural_claim_sources';

    public $timestamps = false;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'calendar_event_id',
        'cultural_claim_id',
        'event_source_id',
        'citation_locator',
        'citation_excerpt',
        'verification_status',
        'verified_by_user_id',
        'verified_at',
        'applicable_academic_year',
        'notes',
        'is_detached',
        'detached_at',
        'detached_by_user_id',
        'detachment_reason',
        'previous_verification_status',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'is_detached' => 'boolean',
            'detached_at' => 'datetime',
            'verified_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'calendar_event_id');
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(CulturalClaim::class, 'cultural_claim_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(EventSource::class, 'event_source_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    public function detachedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'detached_by_user_id');
    }

    public function auditEvents(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CulturalClaimEvidenceAuditEvent::class, 'cultural_claim_source_id')->orderBy('created_at', 'asc');
    }
}
