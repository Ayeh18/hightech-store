<?php

namespace App\Policies;

use App\Models\Address;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/** An address belongs to one customer. Anyone else gets a 404. */
class AddressPolicy
{
    public function update(User $user, Address $address): Response
    {
        return $this->owner($user, $address);
    }

    public function delete(User $user, Address $address): Response
    {
        return $this->owner($user, $address);
    }

    private function owner(User $user, Address $address): Response
    {
        return $user->id === $address->user_id ? Response::allow() : Response::denyAsNotFound();
    }
}
