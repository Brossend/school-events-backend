<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VerificationFinding extends Model
{
    use HasFactory, HasUuid7;

    protected $table = 'verification_findings';

    public $timestamps = false;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'verification_run_id',
        'severity',
        'rule_code',
        'target_path',
        'message',
        'suggested_fix',
        'status',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function verificationRun(): BelongsTo
    {
        return $this->belongsTo(VerificationRun::class, 'verification_run_id');
    }
}
