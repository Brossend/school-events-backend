<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventSource extends Model
{
    use HasFactory, HasUuid7;

    protected $table = 'event_sources';

    public $timestamps = false;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'calendar_event_id',
        'source_type',
        'title',
        'author',
        'url',
        'doi_or_isbn',
        'verified_by_user_id',
        'verification_date',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'verification_date' => 'date',
            'created_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'calendar_event_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(SourceReview::class, 'event_source_id');
    }

    public function claimSources(): HasMany
    {
        return $this->hasMany(CulturalClaimSource::class, 'event_source_id');
    }
}
