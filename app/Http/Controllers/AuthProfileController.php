<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateOwnProfileRequest;
use App\Http\Requests\UploadOwnAvatarRequest;
use App\Http\Resources\OwnProfileResource;
use App\Services\User\UserProfileService;
use Illuminate\Http\Request;

class AuthProfileController extends Controller
{
    public function __construct(
        private UserProfileService $userProfileService
    ) {}

    public function show(Request $request)
    {
        $user = $request->user()->load('profile');

        return OwnProfileResource::make($user);
    }

    public function update(UpdateOwnProfileRequest $request)
    {
        $user = $request->user();
        $this->userProfileService->updateProfile($user, $request->validated());

        return OwnProfileResource::make($user->fresh()->load('profile'));
    }

    public function uploadAvatar(UploadOwnAvatarRequest $request)
    {
        $user = $request->user();
        $this->userProfileService->replaceAvatar($user, $request->file('avatar'));

        return OwnProfileResource::make($user->fresh()->load('profile'));
    }

    public function deleteAvatar(Request $request)
    {
        $user = $request->user();
        $this->userProfileService->removeAvatar($user);

        return response()->json([
            'message' => 'Avatar eliminado exitosamente',
            'avatar_url_resolved' => $user->fresh()->load('profile')->resolved_avatar_url,
        ]);
    }
}