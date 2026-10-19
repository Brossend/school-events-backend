<?php

namespace App\Models;

use App\Models\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchoolMembership extends Model
{
    use HasFactory, HasUuid7;

    protected $table = 'school_memberships';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'school_id',
        'user_id',
        'role',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    /**
     * School relationship.
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class, 'school_id');
    }

    /**
     * User relationship.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
