<?php

namespace App\Models;

use App\Models\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuid7, Notifiable;

    protected $table = 'users';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'email',
        'password',
        'system_role',
        'curator_scope',
        'curated_region_codes',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'curated_region_codes' => 'array',
        ];
    }

    /**
     * User's school memberships.
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(SchoolMembership::class, 'user_id');
    }

    /**
     * Associated schools via memberships.
     */
    public function schools(): HasManyThrough
    {
        return $this->hasManyThrough(
            School::class,
            SchoolMembership::class,
            'user_id',
            'id',
            'id',
            'school_id'
        );
    }

    /**
     * Check if user is a system admin.
     */
    public function isSystemAdmin(): bool
    {
        return $this->system_role === 'system_admin';
    }

    /**
     * Check if user is a catalog curator.
     */
    public function isCurator(): bool
    {
        return $this->system_role === 'catalog_curator';
    }

    /**
     * Check if user has specific role in a school.
     */
    public function hasSchoolRole(string $schoolId, string $role): bool
    {
        return $this->memberships()
            ->where('school_id', $schoolId)
            ->where('role', $role)
            ->exists();
    }

    /**
     * Get default school membership for this user.
     */
    public function defaultSchoolMembership(): ?SchoolMembership
    {
        return $this->memberships()
            ->where('is_default', true)
            ->first();
    }
}
