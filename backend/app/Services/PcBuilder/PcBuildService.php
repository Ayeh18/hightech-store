<?php

namespace App\Services\PcBuilder;

use App\Models\PcBuild;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Saving, editing, duplicating and deleting a customer's saved builds. */
class PcBuildService
{
    public const MAX_BUILDS_PER_USER = 25;

    public function __construct(private readonly BuildPricer $pricer) {}

    /**
     * @param  array<int, array{slot: string, product_id: int, quantity?: int}>  $items
     */
    public function create(User $user, string $name, array $items): PcBuild
    {
        $this->ensureUnderLimit($user);
        $this->pricer->priceSelection($items); // rejects unknown products and parts in the wrong slot

        return DB::transaction(function () use ($user, $name, $items) {
            $build = $user->pcBuilds()->create(['name' => $name]);
            $this->writeItems($build, $items);

            return $build;
        });
    }

    /**
     * @param  array<int, array{slot: string, product_id: int, quantity?: int}>|null  $items  null keeps the parts as they are
     */
    public function update(PcBuild $build, ?string $name, ?array $items): PcBuild
    {
        if ($items !== null) {
            $this->pricer->priceSelection($items);
        }

        return DB::transaction(function () use ($build, $name, $items) {
            if ($name !== null) {
                $build->name = $name;
            }

            $build->save();
            $build->touch(); // the list is ordered by "last edited"

            if ($items !== null) {
                $build->items()->delete();
                $this->writeItems($build, $items);
            }

            return $build;
        });
    }

    public function duplicate(PcBuild $build): PcBuild
    {
        $this->ensureUnderLimit($build->user);

        return DB::transaction(function () use ($build) {
            $copy = $build->user->pcBuilds()->create(['name' => Str::limit('Copy of '.$build->name, 100, '')]);

            foreach ($build->items as $item) {
                $copy->items()->create($item->only(['product_id', 'slot', 'quantity']));
            }

            return $copy;
        });
    }

    public function delete(PcBuild $build): void
    {
        $build->delete();
    }

    /** @param  array<int, array{slot: string, product_id: int, quantity?: int}>  $items */
    private function writeItems(PcBuild $build, array $items): void
    {
        foreach ($items as $item) {
            $build->items()->create([
                'slot' => $item['slot'],
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'] ?? 1,
            ]);
        }
    }

    private function ensureUnderLimit(User $user): void
    {
        if ($user->pcBuilds()->count() >= self::MAX_BUILDS_PER_USER) {
            throw ValidationException::withMessages([
                'name' => 'You can save up to '.self::MAX_BUILDS_PER_USER.' builds. Delete one to save a new build.',
            ]);
        }
    }
}
