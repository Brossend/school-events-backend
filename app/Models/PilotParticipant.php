<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PilotParticipant extends Model
{
    use HasFactory, HasUuid7;

    protected $table = 'pilot_participants';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'school_id',
        'anonymous_code',
        'invite_token_hash',
        'subject_area',
        'teaching_experience_years',
        'is_submitted',
        'expires_at',
        'revoked_at',
        'used_at',
        'is_synthetic',
    ];

    protected function casts(): array
    {
        return [
            'teaching_experience_years' => 'integer',
            'is_submitted' => 'boolean',
            'is_synthetic' => 'boolean',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function scopeReal($query)
    {
        return $query->where('is_synthetic', false);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class, 'school_id');
    }

    public function response(): HasOne
    {
        return $this->hasOne(PilotResponse::class, 'participant_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isValidForSubmission(): bool
    {
        return ! $this->is_submitted && ! $this->isRevoked() && ! $this->isExpired();
    }
}
