<?php

namespace App\Services\Catalog;

use App\Models\Brand;
use App\Models\Category;
use App\Models\ProductSpecification;
use App\Models\SpecDefinition;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Admin writes for the "structure" of the catalog: categories, brands and spec definitions. */
class TaxonomyService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array<string, mixed>  $data */
    public function createCategory(array $data, User $actor): Category
    {
        $category = Category::create([...$data, 'slug' => $data['slug'] ?? Str::slug($data['name'])]);
        $this->audit->log('category.created', $actor, $category, null, ['slug' => $category->slug, 'name' => $category->name]);

        return $category;
    }

    /** @param  array<string, mixed>  $data */
    public function updateCategory(Category $category, array $data, User $actor): Category
    {
        return $this->update($category, $data, $actor, 'category.updated');
    }

    public function deleteCategory(Category $category, User $actor): void
    {
        $this->guardUnused($category->products()->withTrashed()->exists(), 'category', 'products');
        $this->audit->log('category.deleted', $actor, $category, ['slug' => $category->slug]);
        $category->delete();
    }

    /** @param  array<string, mixed>  $data */
    public function createBrand(array $data, User $actor): Brand
    {
        $brand = Brand::create([...$data, 'slug' => $data['slug'] ?? Str::slug($data['name'])]);
        $this->audit->log('brand.created', $actor, $brand, null, ['slug' => $brand->slug, 'name' => $brand->name]);

        return $brand;
    }

    /** @param  array<string, mixed>  $data */
    public function updateBrand(Brand $brand, array $data, User $actor): Brand
    {
        return $this->update($brand, $data, $actor, 'brand.updated');
    }

    public function deleteBrand(Brand $brand, User $actor): void
    {
        $this->guardUnused($brand->products()->withTrashed()->exists(), 'brand', 'products');
        $this->audit->log('brand.deleted', $actor, $brand, ['slug' => $brand->slug]);
        $brand->delete();
    }

    /** @param  array<string, mixed>  $data */
    public function createSpecDefinition(Category $category, array $data, User $actor): SpecDefinition
    {
        $definition = $category->specDefinitions()->create($data);
        $this->audit->log('spec_definition.created', $actor, $definition, null, ['category' => $category->slug, 'key' => $definition->key]);

        return $definition;
    }

    /** The key and type are fixed once created, because products already store values under them. */
    public function updateSpecDefinition(SpecDefinition $definition, array $data, User $actor): SpecDefinition
    {
        return $this->update($definition, $data, $actor, 'spec_definition.updated');
    }

    public function deleteSpecDefinition(SpecDefinition $definition, User $actor): void
    {
        DB::transaction(function () use ($definition, $actor) {
            ProductSpecification::where('key', $definition->key)
                ->whereIn('product_id', fn ($q) => $q->select('id')->from('products')->where('category_id', $definition->category_id))
                ->delete();

            $this->audit->log('spec_definition.deleted', $actor, $definition, ['key' => $definition->key]);
            $definition->delete();
        });
    }

    /**
     * @template TModel of Model
     *
     * @param  TModel  $model
     * @param  array<string, mixed>  $data
     * @return TModel
     */
    private function update(Model $model, array $data, User $actor, string $action): Model
    {
        $model->fill($data);
        $changes = $model->getDirty();
        $old = array_intersect_key($model->getOriginal(), $changes);
        $model->save();

        if ($changes !== []) {
            $this->audit->log($action, $actor, $model, $old, $changes);
        }

        return $model;
    }

    private function guardUnused(bool $inUse, string $what, string $dependents): void
    {
        if ($inUse) {
            throw ValidationException::withMessages([$what => "This {$what} still has {$dependents}. Move or delete them first."]);
        }
    }
}
