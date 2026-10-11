<?php

namespace App\Http\Controllers\Api\PcBuilder;

use App\Enums\BuildPriority;
use App\Enums\BuildPurpose;
use App\Enums\TargetResolution;
use App\Http\Controllers\Controller;
use App\Http\Requests\PcBuilder\RecommendBuildRequest;
use App\Http\Resources\PcBuilder\BuildRecommendationResource;
use App\Models\BuildRecommendation;
use App\Services\Recommendations\Exceptions\CannotRecommend;
use App\Services\Recommendations\RecommendationService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class RecommendationController extends Controller
{
    /**
     * What a build can be suggested for.
     *
     * The choices for the "suggest a build" form, so that their names live in one place. `uses_ai` says whether an AI
     * service is asked first on this shop; game titles are only worth asking for when it is.
     */
    public function options(): JsonResponse
    {
        $choices = fn (array $cases) => array_map(fn ($case) => ['value' => $case->value, 'label' => $case->label()], $cases);

        return response()->json(['data' => [
            'purposes' => $choices(BuildPurpose::cases()),
            'priorities' => $choices(BuildPriority::cases()),
            'resolutions' => $choices(TargetResolution::cases()),
            'budget' => [
                'min' => Money::fromSen(RecommendBuildRequest::MIN_BUDGET_SEN),
                'max' => Money::fromSen(RecommendBuildRequest::MAX_BUDGET_SEN),
            ],
            'max_games' => RecommendBuildRequest::MAX_GAMES,
            /** @var bool */
            'uses_ai' => config('recommender.driver') === 'ai' && filled(config('recommender.ai.api_key')) && filled(config('recommender.ai.model')),
        ]]);
    }

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
