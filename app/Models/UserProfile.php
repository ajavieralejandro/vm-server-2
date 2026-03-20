<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'display_name',
        'app_phone',
        'bio',
        'avatar_path',
        'preferences',
        'avatar_updated_at',
    ];

    protected $casts = [
        'preferences' => 'array',
        'avatar_updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function avatarUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->avatar_path ? asset("storage/{$this->avatar_path}") : null,
        );
    }

    public function hasCustomAvatar(): bool
    {
        return !empty($this->avatar_path);
    }
}