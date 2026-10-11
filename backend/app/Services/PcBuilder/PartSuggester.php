<?php

namespace App\Services\PcBuilder;

use App\Enums\BuilderSlot;
use App\Models\Category;
use App\Models\Product;
use App\Services\Catalog\ProductFilters;
use App\Services\Catalog\ProductSearch;
use App\Services\Compatibility\BuildContext;
use App\Services\Compatibility\CompatibilityReport;
use App\Services\Compatibility\CompatibilityService;
use App\Services\Compatibility\Part;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Smart component filtering. Given what is already in the build, it lists the parts for one slot with the
 * compatible ones first, warnings next and incompatible ones last (or hidden). The normal filters, search
 * and sorting still apply and decide the order inside each group.
 */
class PartSuggester
{
    /** Candidates are checked in memory, so cap how many one request evaluates. */
    public const MAX_CANDIDATES = 500;

    private const ORDER = [CompatibilityReport::COMPATIBLE => 0, CompatibilityReport::WARNING => 1, CompatibilityReport::INCOMPATIBLE => 2];

    public function __construct(private readonly ProductSearch $search, private readonly CompatibilityService $compatibility) {}

    /**
     * @param  array<string, array{id: int, qty?: int}>  $selected  the build so far, keyed by slot value
     */
    public function suggest(BuilderSlot $slot, ProductFilters $filters, array $selected, bool $includeIncompatible, int $page): PartSuggestions
    {
        $category = Category::active()->where('builder_slot', $slot->value)->first();
        $filters = $filters->withCategory($category->slug ?? '__none__');
        $others = $this->contextWithout($slot, $selected);
        $quantity = max(1, (int) ($selected[$slot->value]['qty'] ?? 1));

        // Nothing else chosen yet: every part is compatible, so let the database do the paging.
        if ($others->isEmpty()) {
            $paginator = $this->search->paginate($filters);
            $candidates = $paginator->getCollection()->map(fn (Product $p) => new CandidatePart($p, new CompatibilityReport([])))->all();

            return new PartSuggestions($paginator->setCollection(collect($candidates)), 0, [CompatibilityReport::COMPATIBLE => $paginator->total()]);
        }

        $rules = $this->compatibility->rules();
        $all = $this->search->get($filters, self::MAX_CANDIDATES)
            ->map(fn (Product $p) => new CandidatePart($p, $this->compatibility->checkCandidate($others, Part::fromProduct($slot, $p, $quantity), $rules)));

        $counts = $all->countBy(fn (CandidatePart $c) => $c->report->status())->all();
        $hidden = 0;

        if (! $includeIncompatible) {
            $hidden = $counts[CompatibilityReport::INCOMPATIBLE] ?? 0;
            $all = $all->reject(fn (CandidatePart $c) => $c->report->status() === CompatibilityReport::INCOMPATIBLE);
        }

        // sortBy is stable, so the requested order (price, popularity, relevance...) survives inside each group.
        $sorted = $all->sortBy(fn (CandidatePart $c) => self::ORDER[$c->report->status()])->values();
        $perPage = $filters->perPage;
        $paginator = new LengthAwarePaginator($sorted->forPage($page, $perPage)->values(), $sorted->count(), $perPage, $page);

        return new PartSuggestions($paginator, $hidden, $counts);
    }

    /** @param  array<string, array{id: int, qty?: int}>  $selected */
    private function contextWithout(BuilderSlot $slot, array $selected): BuildContext
    {
        $others = array_diff_key($selected, [$slot->value => true]);
        $products = Product::visible()
            ->with(['category', 'specifications'])
            ->whereIn('id', array_column($others, 'id'))
            ->get()
            ->keyBy('id');

        $parts = [];

        foreach ($others as $slotKey => $entry) {
            $product = $products->get($entry['id']);
            $otherSlot = BuilderSlot::tryFrom($slotKey);

            // Ignore anything that does not belong in its slot: the browser's claim is never trusted.
            if ($product && $otherSlot && $product->category->builder_slot === $otherSlot) {
                $parts[] = Part::fromProduct($otherSlot, $product, max(1, (int) ($entry['qty'] ?? 1)));
            }
        }

        return BuildContext::of($parts);
    }
}
