<?php

namespace App\Policies;

use App\Models\PcBuild;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/** A saved build is private: only its owner can see or change it. */
class PcBuildPolicy
{
    public function view(User $user, PcBuild $build): Response
    {
        return $this->owner($user, $build);
    }

    public function update(User $user, PcBuild $build): Response
    {
        return $this->owner($user, $build);
    }

    public function delete(User $user, PcBuild $build): Response
    {
        return $this->owner($user, $build);
    }

    /** Anyone else gets a 404, so build ids cannot be used to discover what other people saved. */
    private function owner(User $user, PcBuild $build): Response
    {
        return $user->id === $build->user_id ? Response::allow() : Response::denyAsNotFound();
    }
}
