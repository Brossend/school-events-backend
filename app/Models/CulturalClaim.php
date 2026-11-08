<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CulturalClaim extends Model
{
    use HasFactory, HasUuid7;

    protected $table = 'cultural_claims';

    public $timestamps = false;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'calendar_event_id',
        'claim_text',
        'culture_name',
        'provenance_notes',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'calendar_event_id');
    }

    public function evidenceRecords(): HasMany
    {
        return $this->hasMany(CulturalClaimSource::class, 'cultural_claim_id');
    }

    public function evidenceAuditEvents(): HasMany
    {
        return $this->hasMany(CulturalClaimEvidenceAuditEvent::class, 'cultural_claim_id')->orderBy('created_at', 'asc');
    }
}
