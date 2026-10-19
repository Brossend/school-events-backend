<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchoolTradition extends Model
{
    use HasFactory, HasUuid7;

    protected $table = 'school_traditions';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'school_id',
        'title',
        'description',
        'established_year',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'established_year' => 'integer',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class, 'school_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(TraditionVersion::class, 'tradition_id');
    }
}
