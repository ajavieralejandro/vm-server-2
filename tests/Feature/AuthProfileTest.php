<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'dni' => '44555666',
            'name' => 'Perfil Test',
            'email' => 'perfil@test.com',
            'password' => bcrypt('password123'),
            'foto_url' => 'https://clubvillamitre.com/images/socios/123.jpg',
        ]);
    }

    /** @test */
    public function authenticated_user_can_read_own_profile_without_breaking_existing_user_data()
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/auth/profile');

        $response->assertOk();
        $response->assertJsonPath('data.user.id', $this->user->id);
        $response->assertJsonPath('data.user.foto_url', 'https://clubvillamitre.com/images/socios/123.jpg');
        $response->assertJsonPath('data.avatar_url_resolved', 'https://clubvillamitre.com/images/socios/123.jpg');
        $response->assertJsonPath('data.profile.display_name', null);
    }

    /** @test */
    public function authenticated_user_can_update_only_own_profile_fields()
    {
        Sanctum::actingAs($this->user);

        $response = $this->patchJson('/api/auth/profile', [
            'display_name' => 'Javi App',
            'app_phone' => '2915551234',
            'bio' => 'Bio de prueba',
            'preferences' => ['theme' => 'light'],
            'dni' => '00000000',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.profile.display_name', 'Javi App');
        $response->assertJsonPath('data.profile.app_phone', '2915551234');
        $response->assertJsonMissingPath('data.profile.dni');

        $this->assertDatabaseHas('user_profiles', [
            'user_id' => $this->user->id,
            'display_name' => 'Javi App',
            'app_phone' => '2915551234',
            'bio' => 'Bio de prueba',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
            'dni' => '44555666',
        ]);
    }

    /** @test */
    public function authenticated_user_can_upload_replace_and_delete_avatar_without_touching_foto_url()
    {
        Storage::fake('public');
        Sanctum::actingAs($this->user);

        $uploadResponse = $this->postJson('/api/auth/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.png', 300, 300),
        ]);

        $uploadResponse->assertOk();
        $uploadResponse->assertJsonPath('data.user.foto_url', 'https://clubvillamitre.com/images/socios/123.jpg');

        $profile = UserProfile::where('user_id', $this->user->id)->firstOrFail();
        Storage::disk('public')->assertExists($profile->avatar_path);

        $firstAvatarPath = $profile->avatar_path;

        $replaceResponse = $this->postJson('/api/auth/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.webp', 300, 300),
        ]);

        $replaceResponse->assertOk();

        $profile = $profile->fresh();
        $this->assertNotSame($firstAvatarPath, $profile->avatar_path);
        Storage::disk('public')->assertMissing($firstAvatarPath);
        Storage::disk('public')->assertExists($profile->avatar_path);
        $replaceResponse->assertJsonPath('data.avatar_url_resolved', $profile->avatar_url);

        $deleteResponse = $this->deleteJson('/api/auth/profile/avatar');

        $deleteResponse->assertOk();
        $deleteResponse->assertJsonPath('avatar_url_resolved', 'https://clubvillamitre.com/images/socios/123.jpg');

        $profile = $profile->fresh();
        $this->assertNull($profile->avatar_path);
    }

    /** @test */
    public function auth_me_remains_compatible_when_profile_exists()
    {
        Sanctum::actingAs($this->user);
        $this->user->profile()->create([
            'display_name' => 'Alias',
            'avatar_path' => 'avatars/'.$this->user->id.'/avatar.jpg',
        ]);

        $response = $this->getJson('/api/auth/me');

        $response->assertOk();
        $response->assertJsonPath('data.user.id', $this->user->id);
        $response->assertJsonPath('data.user.foto_url', 'https://clubvillamitre.com/images/socios/123.jpg');
    }
}