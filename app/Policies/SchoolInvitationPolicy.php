<?php

namespace App\Policies;

use App\Models\School;
use App\Models\User;

class SchoolInvitationPolicy
{
    public function manage(User $user, School $school): bool
    {
        return $user->is_active && ($user->isSystemAdmin() || $user->hasSchoolRole($school->id, 'school_admin'));
    }
}
