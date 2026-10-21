<?php

namespace App\Policies;

use App\Models\TopicShort;
use App\Models\User;

class TopicShortPolicy
{
    public function view(User $user, TopicShort $short): bool
    {
        return $short->user_id === $user->id;
    }

    public function update(User $user, TopicShort $short): bool
    {
        return $short->user_id === $user->id;
    }

    public function delete(User $user, TopicShort $short): bool
    {
        return $short->user_id === $user->id;
    }
}
