<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'display_name' => $this->display_name,
            'app_phone' => $this->app_phone,
            'bio' => $this->bio,
            'preferences' => $this->preferences,
            'avatar_url' => $this->avatar_url,
            'avatar_updated_at' => $this->avatar_updated_at?->toISOString(),
        ];
    }
}