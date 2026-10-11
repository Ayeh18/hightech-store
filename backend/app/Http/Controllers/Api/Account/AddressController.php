<?php

namespace App\Http\Controllers\Api\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\AddressRequest;
use App\Http\Resources\Account\AddressResource;
use App\Models\Address;
use App\Services\Orders\AddressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class AddressController extends Controller
{
    public function __construct(private readonly AddressService $addresses) {}

    /** List my delivery addresses. */
    public function index(Request $request): AnonymousResourceCollection
    {
        return AddressResource::collection($request->user()->addresses()->orderByDesc('is_default')->orderBy('id')->get());
    }

    /** Add a delivery address. */
    public function store(AddressRequest $request): JsonResponse
    {
        $address = $this->addresses->create($request->user(), $request->validated());

        return (new AddressResource($address))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /** Change one of my delivery addresses. */
    public function update(AddressRequest $request, Address $address): AddressResource
    {
        Gate::authorize('update', $address);

        return new AddressResource($this->addresses->update($address, $request->validated()));
    }

    /** Delete one of my delivery addresses. */
    public function destroy(Address $address): Response
    {
        Gate::authorize('delete', $address);

        $this->addresses->delete($address);

        return response()->noContent();
    }
}
