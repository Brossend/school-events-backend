<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TraditionVersion extends Model
{
    use HasFactory, HasUuid7;

    protected $table = 'tradition_versions';

    public $timestamps = false;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tradition_id',
        'academic_year',
        'parameters_snapshot',
        'notes',
        'created_by_user_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'parameters_snapshot' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('TraditionVersion is strictly append-only and immutable. Overwriting versions is prohibited.');
        });

        static::deleting(function () {
            throw new \LogicException('TraditionVersion is strictly append-only and immutable. Deleting versions is prohibited.');
        });
    }

    public function tradition(): BelongsTo
    {
        return $this->belongsTo(SchoolTradition::class, 'tradition_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
