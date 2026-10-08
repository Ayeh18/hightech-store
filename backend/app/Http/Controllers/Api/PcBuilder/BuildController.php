<?php

namespace App\Http\Controllers\Api\PcBuilder;

use App\Http\Controllers\Controller;
use App\Http\Requests\PcBuilder\SaveBuildRequest;
use App\Http\Resources\PcBuilder\PcBuildResource;
use App\Http\Resources\PcBuilder\PcBuildSummaryResource;
use App\Models\PcBuild;
use App\Services\PcBuilder\PcBuildService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class BuildController extends Controller
{
    /** Relations needed to show every part of a build with its photo, specs and stock. */
    private const FULL = ['items.product.brand', 'items.product.category.specDefinitions', 'items.product.images', 'items.product.inventory', 'items.product.specifications'];

    public function __construct(private readonly PcBuildService $builds) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $builds = $request->user()->pcBuilds()
            ->with(['items.product.category', 'items.product.inventory'])
            ->latest('updated_at')
            ->get();

        return PcBuildSummaryResource::collection($builds);
    }

    public function store(SaveBuildRequest $request): JsonResponse
    {
        $build = $this->builds->create($request->user(), $request->validated('name'), $request->selection());

        return (new PcBuildResource($build->load(self::FULL)))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(PcBuild $build): PcBuildResource
    {
        Gate::authorize('view', $build);

        return new PcBuildResource($build->load(self::FULL));
    }

    public function update(SaveBuildRequest $request, PcBuild $build): PcBuildResource
    {
        Gate::authorize('update', $build);

        $this->builds->update($build, $request->validated('name'), $request->selectionOrNull());

        return new PcBuildResource($build->load(self::FULL));
    }

    public function destroy(PcBuild $build): Response
    {
        Gate::authorize('delete', $build);

        $this->builds->delete($build);

        return response()->noContent();
    }

    public function duplicate(PcBuild $build): JsonResponse
    {
        Gate::authorize('view', $build);

        $copy = $this->builds->duplicate($build->load('items', 'user'));

        return (new PcBuildResource($copy->load(self::FULL)))->response()->setStatusCode(Response::HTTP_CREATED);
    }
}
