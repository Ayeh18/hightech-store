<?php

namespace App\Http\Controllers\Api\PcBuilder;

use App\Http\Controllers\Controller;
use App\Http\Resources\PcBuilder\SharedBuildResource;
use App\Models\PcBuild;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Sharing a saved build. A build is private until its owner asks for a link; the link carries a long random token, never
 * the build's id, and shows the build read-only without saying whose it is.
 */
class BuildShareController extends Controller
{
    /** Relations needed to show every part of a build with its photo, specs and stock. */
    private const FULL = ['items.product.brand', 'items.product.category.specDefinitions', 'items.product.images', 'items.product.inventory', 'items.product.specifications'];

    /**
     * Create a public link to one of my builds.
     *
     * Anyone with the link can see the build's name and parts, at the shop's current prices, and copy it into their own
     * builder. They cannot change it, and it does not say who made it. Asking again returns the same link.
     */
    public function store(PcBuild $build): JsonResponse
    {
        Gate::authorize('update', $build);
        $build->share();

        return response()->json(['data' => self::link($build)]);
    }

    /**
     * Stop sharing one of my builds.
     *
     * The link stops working at once and for good; sharing the build again later makes a new, different link.
     */
    public function destroy(PcBuild $build): Response
    {
        Gate::authorize('update', $build);
        $build->stopSharing();

        return response()->noContent();
    }

    /**
     * Show a shared build.
     *
     * Public: the token in the link is the permission. The build is priced and checked as it is now, so it may differ
     * from what its owner saw when they shared it. A part that is no longer on sale is listed as unavailable, unnamed.
     */
    public function show(string $token): SharedBuildResource
    {
        // An exact, case-sensitive match on the whole token. A token that is too short or too long is not looked up.
        abort_unless(strlen($token) === PcBuild::SHARE_TOKEN_LENGTH && ctype_alnum($token), 404);

        return new SharedBuildResource(PcBuild::where('share_token', $token)->with(self::FULL)->firstOrFail());
    }

    /** @return array{token: string, path: string, shared_at: string|null} */
    public static function link(PcBuild $build): array
    {
        return [
            'token' => (string) $build->share_token,
            // Where the website shows it. The website adds its own address in front.
            'path' => "/shared/{$build->share_token}",
            'shared_at' => $build->shared_at?->toIso8601String(),
        ];
    }
}
