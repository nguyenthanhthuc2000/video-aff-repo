<?php

namespace App\Policies;

use App\Models\AudioTrack;
use App\Models\User;

class AudioTrackPolicy
{
    public function update(User $user, AudioTrack $audioTrack): bool
    {
        return $audioTrack->user_id === $user->id;
    }

    public function delete(User $user, AudioTrack $audioTrack): bool
    {
        return $audioTrack->user_id === $user->id;
    }
}
