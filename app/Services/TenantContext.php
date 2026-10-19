<?php

namespace App\Services;

use App\Models\School;
use App\Models\SchoolMembership;

class TenantContext
{
    protected ?School $school = null;
    protected ?SchoolMembership $membership = null;

    /**
     * Set active tenant context.
     */
    public function set(School $school, SchoolMembership $membership): void
    {
        $this->school = $school;
        $this->membership = $membership;
    }

    /**
     * Get active school.
     */
    public function getSchool(): ?School
    {
        return $this->school;
    }

    /**
     * Get active school ID.
     */
    public function getSchoolId(): ?string
    {
        return $this->school?->id;
    }

    /**
     * Get active membership.
     */
    public function getMembership(): ?SchoolMembership
    {
        return $this->membership;
    }

    /**
     * Get user role in the active school.
     */
    public function getRole(): ?string
    {
        return $this->membership?->role;
    }

    /**
     * Check if a tenant context is active.
     */
    public function hasTenant(): bool
    {
        return $this->school !== null;
    }

    /**
     * Clear the tenant context.
     */
    public function clear(): void
    {
        $this->school = null;
        $this->membership = null;
    }
}
