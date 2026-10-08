<?php

namespace App\Http\Controllers\Api\Cart;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cart\AddBuildToCartRequest;
use App\Http\Resources\Cart\CartResource;
use App\Models\PcBuild;
use App\Services\Cart\CartEvaluator;
use App\Services\Cart\CartService;
use App\Services\PcBuilder\BuildEvaluator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class CartBuildController extends Controller
{
    /**
     * Add a whole PC from a saved build or from the parts in the builder. The server re-validates it (complete, in
     * stock, compatible) and stores a snapshot of its parts and prices in the cart.
     */
    public function store(AddBuildToCartRequest $request, BuildEvaluator $builds, CartService $cart, CartEvaluator $evaluator): JsonResponse
    {
        $savedId = $request->validated('build_id');

        if ($savedId !== null) {
            // Someone else's build (or one that does not exist) is a 404, exactly like everywhere else.
            $build = PcBuild::findOrFail($savedId);
            Gate::authorize('view', $build);
            $build->load(['items.product.category', 'items.product.inventory', 'items.product.specifications']);

            $evaluated = $builds->evaluateBuild($build);
            $name = $request->buildName() ?? $build->name;
        } else {
            $evaluated = $builds->evaluateSelection($request->selection());
            $name = $request->buildName() ?? 'Custom PC';
        }

        $cart->addBuild($request->user(), $evaluated, $name, $savedId, $request->quantity());

        return (new CartResource($evaluator->evaluate($cart->cartFor($request->user()))))
            ->response()->setStatusCode(Response::HTTP_CREATED);
    }
}
