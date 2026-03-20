<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OwnProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'user' => UserResource::make($this->resource),
            'avatar_url_resolved' => $this->resolved_avatar_url,
            'profile' => $this->profile
                ? UserProfileResource::make($this->profile)->toArray($request)
                : [
                    'display_name' => null,
                    'app_phone' => null,
                    'bio' => null,
                    'preferences' => null,
                    'avatar_url' => null,
                    'avatar_updated_at' => null,
                ],
        ];
    }
}