<?php

namespace App\Policies;

use App\Models\SampleVideo;
use App\Models\User;

class SampleVideoPolicy
{
    public function update(User $user, SampleVideo $sampleVideo): bool
    {
        return $sampleVideo->user_id === $user->id;
    }

    public function delete(User $user, SampleVideo $sampleVideo): bool
    {
        return $sampleVideo->user_id === $user->id;
    }
}
