<?php

namespace App\Policies;

use App\Models\FashionModel;
use App\Models\User;

class FashionModelPolicy
{
    public function view(User $user, FashionModel $fashionModel): bool
    {
        return $fashionModel->user_id === $user->id;
    }

    public function update(User $user, FashionModel $fashionModel): bool
    {
        return $fashionModel->user_id === $user->id;
    }

    public function delete(User $user, FashionModel $fashionModel): bool
    {
        return $fashionModel->user_id === $user->id;
    }
}
