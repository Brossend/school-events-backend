<?php

namespace App\Models;

use App\Models\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Model;

class SchoolInvitation extends Model
{
    use HasUuid7;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['school_id', 'email', 'role', 'code_hash', 'expires_at', 'created_by'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'used_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    public function publicData(): array
    {
        return ['id' => $this->id, 'school_id' => $this->school_id, 'role' => $this->role,
            'email' => $this->email, 'expires_at' => $this->expires_at->toIso8601String(),
            'used_at' => $this->used_at?->toIso8601String(), 'revoked_at' => $this->revoked_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String()];
    }
}
