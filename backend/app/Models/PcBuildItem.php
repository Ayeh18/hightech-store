<?php

namespace App\Models;

use App\Enums\BuilderSlot;
use Database\Factories\PcBuildItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['pc_build_id', 'product_id', 'slot', 'quantity'])]
class PcBuildItem extends Model
{
    /** @use HasFactory<PcBuildItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['slot' => BuilderSlot::class];
    }

    /** @return BelongsTo<PcBuild, $this> */
    public function build(): BelongsTo
    {
        return $this->belongsTo(PcBuild::class, 'pc_build_id');
    }

    /**
     * Includes soft-deleted products, so a saved build can still say which part is no longer sold.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
