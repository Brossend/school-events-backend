<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CalendarEvent extends Model
{
    use HasFactory, HasUuid7;

    protected $table = 'calendar_events';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'school_id',
        'title',
        'event_type',
        'region_code',
        'date_type',
        'fixed_month',
        'fixed_day',
        'description',
        'target_age_groups',
        'recommended_value_directions',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'target_age_groups' => 'array',
            'recommended_value_directions' => 'array',
            'fixed_month' => 'integer',
            'fixed_day' => 'integer',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class, 'school_id');
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(EventOccurrence::class, 'calendar_event_id');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(EventSource::class, 'calendar_event_id');
    }

    public function culturalClaims(): HasMany
    {
        return $this->hasMany(CulturalClaim::class, 'calendar_event_id');
    }

    /**
     * Scope query to events visible to the given user and active school tenant.
     *
     * Visibility rules:
     * 1. System administrators see all events.
     * 2. Catalog curators see federal events and regional events within their curator scope.
     * 3. School users see approved federal events, approved regional events for their school's region,
     *    and their own school's custom events.
     * 4. Events from other regions or other schools are strictly invisible (404 on direct lookup).
     */
    public function scopeVisibleTo(Builder $query, ?User $user, ?School $school): Builder
    {
        if ($user && $user->isSystemAdmin()) {
            return $query;
        }

        if ($user && $user->isCurator()) {
            return $query->where(function (Builder $q) use ($user, $school) {
                if ($user->curator_scope === 'global') {
                    $q->whereNull('school_id');
                } else {
                    // Regional curator: federal events + curated regional events
                    $allowedRegions = $user->curated_region_codes ?? [];
                    $q->where(function (Builder $curQ) use ($allowedRegions) {
                        $curQ->whereNull('school_id')
                            ->where(function (Builder $sub) use ($allowedRegions) {
                                $sub->where('event_type', 'federal')
                                    ->orWhere(function (Builder $regSub) use ($allowedRegions) {
                                        $regSub->whereIn('region_code', $allowedRegions);
                                    });
                            });
                    });
                }

                // If curator is also member of a school, allow that school's custom events
                if ($school) {
                    $q->orWhere('school_id', $school->id);
                }
            });
        }

        // Regular school user (teacher, school_admin) or user with active school context
        if ($school) {
            $schoolRegion = $school->region_code;

            return $query->where(function (Builder $q) use ($school, $schoolRegion) {
                // 1. Approved federal catalog events (visible to all schools)
                $q->where(function (Builder $sub) {
                    $sub->whereNull('school_id')
                        ->where('status', 'approved')
                        ->where('event_type', 'federal');
                });

                // 2. Approved regional events matching the school's region
                if ($schoolRegion) {
                    $q->orWhere(function (Builder $sub) use ($schoolRegion) {
                        $sub->whereNull('school_id')
                            ->where('status', 'approved')
                            ->where('event_type', 'regional')
                            ->where('region_code', $schoolRegion);
                    });
                }

                // 3. Approved cultural traditional events (global or matching region)
                $q->orWhere(function (Builder $sub) use ($schoolRegion) {
                    $sub->whereNull('school_id')
                        ->where('status', 'approved')
                        ->where('event_type', 'cultural_traditional')
                        ->where(function (Builder $regSub) use ($schoolRegion) {
                            $regSub->whereNull('region_code')
                                ->orWhere('region_code', $schoolRegion);
                        });
                });

                // 4. Custom events belonging strictly to this school
                $q->orWhere('school_id', $school->id);
            });
        }

        // Fallback for authenticated users without school context: approved federal events only
        return $query->where(function (Builder $q) {
            $q->whereNull('school_id')
                ->where('status', 'approved')
                ->where('event_type', 'federal');
        });
    }
}
