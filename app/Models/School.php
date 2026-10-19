<?php

namespace App\Models;

use App\Models\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class School extends Model
{
    use HasFactory, HasUuid7;

    protected $table = 'schools';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'short_name',
        'region_code',
        'city',
        'inn',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /**
     * School memberships relation.
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(SchoolMembership::class, 'school_id');
    }

    /**
     * Users associated through memberships.
     */
    public function users(): HasManyThrough
    {
        return $this->hasManyThrough(
            User::class,
            SchoolMembership::class,
            'school_id',
            'id',
            'id',
            'user_id'
        );
    }
}
