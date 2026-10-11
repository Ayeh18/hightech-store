<?php

namespace App\Http\Controllers\Api\PcBuilder;

use App\Http\Controllers\Controller;
use App\Http\Requests\PcBuilder\RecommendBuildRequest;
use App\Http\Resources\PcBuilder\BuildRecommendationResource;
use App\Models\BuildRecommendation;
use App\Services\Recommendations\Exceptions\CannotRecommend;
use App\Services\Recommendations\RecommendationService;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class RecommendationController extends Controller
{
    /**
     * Suggest a PC for a budget.
     *
     * Chooses a complete, compatible PC from the products in stock for the budget and purpose given. Whoever does the
     * choosing (the shop's own rules, or an AI service when one is switched on), the answer is checked the same way
     * before it is returned: real products, in stock, compatible, and within the budget at the shop's own prices.
     * A budget below the cheapest possible PC is answered with 422 and that price.
     */
    public function store(RecommendBuildRequest $request, RecommendationService $recommendations): BuildRecommendationResource
    {
        try {
            return new BuildRecommendationResource($recommendations->recommend($request->brief(), $request->user('sanctum')));
        } catch (CannotRecommend $e) {
            if ($e->forCustomer) {
                throw ValidationException::withMessages(['budget' => $e->getMessage()]);
            }

            report($e);
            abort(Response::HTTP_SERVICE_UNAVAILABLE, 'We could not put a build together just now. Please try again in a moment.');
        }
    }

    /**
     * Record that a suggested PC was used.
     *
     * Called when the customer loads a suggestion into the builder. It only feeds the shop's own statistics.
     */
    public function accept(BuildRecommendation $recommendation): Response
    {
        if ($recommendation->accepted_at === null) {
            $recommendation->update(['accepted_at' => now()]);
        }

        return response()->noContent();
    }
}
