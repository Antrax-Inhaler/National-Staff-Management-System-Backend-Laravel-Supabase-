<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Affiliate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Firebase\JWT\JWT;
use PHPUnit\Framework\Attributes\Test;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function generateToken($payload = [])
    {
        $secret = env('SUPABASE_JWT_SECRET');
        $defaultPayload = [
            'sub' => '9e9d2c4d-7ede-44a8-9ebb-15ce5eb7bcae',
            'email' => 'jovenandrei0324@gmail.com',
            'iat' => time(),
            'exp' => time() + 3600,
        ];

        return JWT::encode(array_merge($defaultPayload, $payload), $secret, 'HS256');
    }

    #[Test]
    public function it_returns_member_profile_when_authenticated()
    {
        $affiliate = Affiliate::factory()->create();
        $member = Member::factory()->create([
            'supabase_id' => '9e9d2c4d-7ede-44a8-9ebb-15ce5eb7bcae',
            'affiliate_id' => $affiliate->id,
        ]);

        $token = $this->generateToken(['sub' => '9e9d2c4d-7ede-44a8-9ebb-15ce5eb7bcae']);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/members/profile/me');

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                     'data' => [
                         'id' => $member->id,
                         'supabase_id' => '9e9d2c4d-7ede-44a8-9ebb-15ce5eb7bcae',
                     ],
                 ]);
    }

    #[Test]
    public function it_returns_unauthorized_without_token()
    {
        $response = $this->getJson('/api/members/profile/me');
        $response->assertStatus(401);
    }

    #[Test]
    public function it_returns_not_found_if_member_does_not_exist()
    {
        $token = $this->generateToken(['sub' => 'missing-sub-456']);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/members/profile/me');

        $response->assertStatus(404);
    }
}
