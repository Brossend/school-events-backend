<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceReview extends Model
{
    use HasFactory, HasUuid7;

    protected $table = 'source_reviews';

    public $timestamps = false;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'event_source_id',
        'reviewer_user_id',
        'decision',
        'comments',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Whenever a review decision changes, invalidate previously authentic claims
        static::saved(function (SourceReview $review) {
            if ($review->decision === 'rejected') {
                CulturalClaimSource::where('event_source_id', $review->event_source_id)
                    ->update(['verification_status' => 'rejected_fake']);
            } elseif ($review->decision === 'requires_amendment') {
                CulturalClaimSource::where('event_source_id', $review->event_source_id)
                    ->where('verification_status', 'verified_authentic')
                    ->update(['verification_status' => 'unverified']);
            }
        });
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(EventSource::class, 'event_source_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }
}
