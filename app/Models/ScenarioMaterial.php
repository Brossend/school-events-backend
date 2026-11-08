<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScenarioMaterial extends Model
{
    use HasFactory, HasUuid7;

    protected $table = 'scenario_materials';

    public $timestamps = false;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'revision_id',
        'material_type',
        'title',
        'order_index',
        'content',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'order_index' => 'integer',
            'content' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(ScenarioRevision::class, 'revision_id');
    }
}
