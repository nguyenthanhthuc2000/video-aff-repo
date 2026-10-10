<?php

namespace App\Policies;

use App\Models\Background;
use App\Models\User;

class BackgroundPolicy
{
    public function view(User $user, Background $background): bool
    {
        return $background->user_id === $user->id;
    }

    public function update(User $user, Background $background): bool
    {
        return $background->user_id === $user->id;
    }

    public function delete(User $user, Background $background): bool
    {
        return $background->user_id === $user->id;
    }
}
