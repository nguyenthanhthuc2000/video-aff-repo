<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VideoProject;

class VideoProjectPolicy
{
    public function view(User $user, VideoProject $videoProject): bool
    {
        return $videoProject->user_id === $user->id;
    }

    public function update(User $user, VideoProject $videoProject): bool
    {
        return $videoProject->user_id === $user->id;
    }

    public function delete(User $user, VideoProject $videoProject): bool
    {
        return $videoProject->user_id === $user->id;
    }
}
