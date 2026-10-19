<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventOccurrence extends Model
{
    use HasFactory, HasUuid7;

    protected $table = 'event_occurrences';

    public $timestamps = false;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'calendar_event_id',
        'academic_year',
        'start_date',
        'end_date',
        'source_reference',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'created_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'calendar_event_id');
    }
}
