<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicTemplatesBypassTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function bypass_disabled_returns_unauthorized_for_no_token_requests()
    {
        config(['services.public_templates.bypass_enabled' => false]);
        config(['services.public_templates.demo_key' => 'demo-secret']);

        $response = $this->getJson('/api/public/student/my-templates?dni=12345678', [
            'X-Demo-Key' => 'demo-secret',
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'ok' => false,
            'message' => 'Unauthorized',
        ]);
    }

    /** @test */
    public function invalid_dni_returns_422_when_bypass_enabled_and_demo_key_valid()
    {
        config(['services.public_templates.bypass_enabled' => true]);
        config(['services.public_templates.demo_key' => 'demo-secret']);

        $response = $this->getJson('/api/public/student/my-templates?dni=abc123', [
            'X-Demo-Key' => 'demo-secret',
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'ok' => false,
            'message' => 'Invalid dni',
        ]);
    }
}
