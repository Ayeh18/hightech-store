<?php

namespace App\Models;

use Database\Factories\PcBuildFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['user_id', 'name'])]
class PcBuild extends Model
{
    /** @use HasFactory<PcBuildFactory> */
    use HasFactory, HasUlids;

    /** Long enough that a link cannot be found by guessing: 40 letters and digits is about 238 bits. */
    public const SHARE_TOKEN_LENGTH = 40;

    // share_token and shared_at are deliberately not fillable: only share() and stopSharing() change them.
    protected function casts(): array
    {
        return ['shared_at' => 'datetime'];
    }

    public function isShared(): bool
    {
        return $this->share_token !== null;
    }

    /** Switch the public link on. Asking again keeps the same link, so one that was already sent keeps working. */
    public function share(): void
    {
        if (! $this->isShared()) {
            $this->forceFill(['share_token' => Str::random(self::SHARE_TOKEN_LENGTH), 'shared_at' => now()])->save();
        }
    }

    /** Switch the public link off. The old link stops working for good; sharing again makes a new one. */
    public function stopSharing(): void
    {
        $this->forceFill(['share_token' => null, 'shared_at' => null])->save();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<PcBuildItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PcBuildItem::class, 'pc_build_id');
    }
}
