<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/** Customers can only see, cancel and pay for their own orders. Anyone else gets a 404, so order ids reveal nothing. */
class OrderPolicy
{
    public function view(User $user, Order $order): Response
    {
        return $this->owner($user, $order);
    }

    public function cancel(User $user, Order $order): Response
    {
        return $this->owner($user, $order);
    }

    public function pay(User $user, Order $order): Response
    {
        return $this->owner($user, $order);
    }

    private function owner(User $user, Order $order): Response
    {
        return $user->id === $order->user_id ? Response::allow() : Response::denyAsNotFound();
    }
}
