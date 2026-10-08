<?php

namespace App\Policies;

use App\Models\CartItem;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/** A cart line belongs to exactly one customer. Anyone else gets a 404, so line ids reveal nothing. */
class CartItemPolicy
{
    public function update(User $user, CartItem $item): Response
    {
        return $this->owner($user, $item);
    }

    public function delete(User $user, CartItem $item): Response
    {
        return $this->owner($user, $item);
    }

    private function owner(User $user, CartItem $item): Response
    {
        return $item->cart->user_id === $user->id ? Response::allow() : Response::denyAsNotFound();
    }
}
