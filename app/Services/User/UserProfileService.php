<?php

namespace App\Services\User;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

class UserProfileService
{
    public function getProfile(User $user): ?UserProfile
    {
        return $user->profile;
    }

    public function updateProfile(User $user, array $data): UserProfile
    {
        $profile = $user->profile()->firstOrCreate([]);

        $allowedData = Arr::only($data, [
            'display_name',
            'app_phone',
            'bio',
            'preferences',
        ]);

        $profile->fill($allowedData);
        $profile->save();

        return $profile->fresh();
    }

    public function replaceAvatar(User $user, UploadedFile $avatar): UserProfile
    {
        $profile = $user->profile()->firstOrCreate([]);
        $newPath = $this->buildAvatarPath($user, $avatar);

        if ($profile->avatar_path && $profile->avatar_path !== $newPath) {
            Storage::disk('public')->delete($profile->avatar_path);
        }

        $avatar->storeAs(
            dirname($newPath),
            basename($newPath),
            'public'
        );

        $profile->forceFill([
            'avatar_path' => $newPath,
            'avatar_updated_at' => now(),
        ])->save();

        return $profile->fresh();
    }

    public function removeAvatar(User $user): ?UserProfile
    {
        $profile = $user->profile;
        if (!$profile) {
            return null;
        }

        if ($profile->avatar_path) {
            Storage::disk('public')->delete($profile->avatar_path);
        }

        $profile->forceFill([
            'avatar_path' => null,
            'avatar_updated_at' => null,
        ])->save();

        return $profile->fresh();
    }

    private function buildAvatarPath(User $user, UploadedFile $avatar): string
    {
        $extension = strtolower($avatar->getClientOriginalExtension() ?: $avatar->extension() ?: 'jpg');

        return sprintf('avatars/%d/avatar.%s', $user->id, $extension);
    }
}