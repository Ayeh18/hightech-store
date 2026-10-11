<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\BuildPriority;
use App\Enums\BuildPurpose;
use App\Enums\TargetResolution;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A build the shop suggested for a budget. See the create_build_recommendations_table migration. */
#[Fillable(['user_id', 'budget', 'purpose', 'priority', 'resolution', 'games', 'source', 'fallback_reason', 'total', 'selection', 'accepted_at'])]
class BuildRecommendation extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'budget' => MoneyCast::class,
            'total' => MoneyCast::class,
            'purpose' => BuildPurpose::class,
            'priority' => BuildPriority::class,
            'resolution' => TargetResolution::class,
            'games' => 'array',
            'selection' => 'array',
            'accepted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
