<?php

namespace App\Models;

use App\Enums\SpecType;
use Database\Factories\SpecDefinitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['category_id', 'key', 'label', 'type', 'unit', 'options', 'is_required', 'is_filterable', 'show_on_card', 'sort_order'])]
class SpecDefinition extends Model
{
    /** @use HasFactory<SpecDefinitionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => SpecType::class,
            'options' => 'array',
            'is_required' => 'boolean',
            'is_filterable' => 'boolean',
            'show_on_card' => 'boolean',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
