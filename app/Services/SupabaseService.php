<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SupabaseService
{
    protected string $baseUrl;
    protected string $serviceKey;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.supabase.url') ?? env('SUPABASE_URL', ''), '/');
        $this->serviceKey = config('services.supabase.key') ?? env('SUPABASE_KEY', '');

        if (empty($this->baseUrl) || empty($this->serviceKey)) {
            throw new \RuntimeException("Supabase URL or Key is not set. Check your .env and config/services.php");
        }
    }

    public function updateUserMetadata(string $supabaseUserId, array $metadata): array
    {
        $url = "{$this->baseUrl}/auth/v1/admin/users/{$supabaseUserId}";
        Log::info("Supabase updateUserMetadata URL: ".$url);

        $response = Http::withHeaders([
            'apikey'        => $this->serviceKey,
            'Authorization' => 'Bearer '.$this->serviceKey,
            'Content-Type'  => 'application/json',
        ])->put($url, [
            'user_metadata' => $metadata,
        ]);

        if ($response->failed()) {
            Log::error("Supabase updateUserMetadata failed", [
                'url' => $url,
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Supabase updateUserMetadata failed: '.$response->body());
        }

        return $response->json();
    }
}
