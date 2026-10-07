<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'roles' => $this->roles->pluck('slug')->values(),
            // The frontend uses these keys only to show/hide UI. The API re-checks every request.
            'permissions' => $this->permissionKeys(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
