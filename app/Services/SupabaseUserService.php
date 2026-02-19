<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SupabaseUserService
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

public function createUser(string $email, string $password): array
{
    $url = "{$this->baseUrl}/auth/v1/admin/users";
    
    Log::info("🔧 Supabase createUser - START", [
        'url' => $url,
        'email' => $email,
        'service_key_first_4' => substr($this->serviceKey, 0, 4) . '...'
    ]);
    
    $payload = [
        'email' => $email,
        'password' => $password,
        'email_confirm' => true,
        'user_metadata' => [
            'name' => $this->name ?? 'User',
            'source' => 'laravel_backend'
        ]
    ];
    
    try {
        $response = Http::timeout(30)
            ->withHeaders([
                'apikey'        => $this->serviceKey,
                'Authorization' => 'Bearer ' . $this->serviceKey,
                'Content-Type'  => 'application/json',
            ])->post($url, $payload);
        
        Log::info("🔧 Supabase createUser - RESPONSE", [
            'status' => $response->status(),
            'response_body' => $response->body()
        ]);
        
        if ($response->status() === 409 || $response->status() === 422) {
            // User already exists, try to find it
            return $this->handleExistingUser($email);
        }
        
        if (!$response->successful()) {
            Log::error("❌ Supabase createUser - FAILED", [
                'status' => $response->status(),
                'body' => $response->body(),
                'url' => $url
            ]);
            
            throw new \RuntimeException(
                "Supabase user creation failed: " . $response->body()
            );
        }
        
        $data = $response->json();
        
        Log::info("✅ Supabase createUser - SUCCESS", [
            'supabase_uid' => $data['id'] ?? null,
            'email' => $data['email'] ?? null
        ]);
        
        return $data;
        
    } catch (\Exception $e) {
        Log::error("💥 Supabase createUser - EXCEPTION", [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        throw $e;
    }
}
private function handleExistingUser(string $email): array
{
    Log::warning("User already exists in Supabase, fetching...", ['email' => $email]);
    
    $url = "{$this->baseUrl}/auth/v1/admin/users";
    
    $response = Http::withHeaders([
        'apikey'        => $this->serviceKey,
        'Authorization' => 'Bearer ' . $this->serviceKey,
    ])->get($url);
    
    if ($response->successful()) {
        $users = $response->json();
        foreach ($users as $user) {
            if (($user['email'] ?? '') === $email) {
                Log::info("Found existing Supabase user", [
                    'email' => $email,
                    'supabase_uid' => $user['id']
                ]);
                return $user;
            }
        }
    }
    
    throw new \RuntimeException("User exists but cannot fetch details");
}

    public function deleteUser(string $supabaseUserId): void
    {
        $url = "{$this->baseUrl}/auth/v1/admin/users/{$supabaseUserId}";
        Log::info("Supabase deleteUser URL: " . $url);

        $response = Http::withHeaders([
            'apikey'        => $this->serviceKey,
            'Authorization' => 'Bearer ' . $this->serviceKey,
        ])->delete($url);

        if ($response->failed()) {
            Log::error("Supabase deleteUser failed", [
                'url' => $url,
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Supabase delete failed: ' . $response->body());
        }
    }
    public function updateUserEmail(string $supabaseUserId, string $newEmail): void
    {
        // Validate inputs
        if (empty($supabaseUserId) || empty($newEmail)) {
            Log::error("Supabase updateUserEmail - Invalid parameters", [
                'supabase_user_id' => $supabaseUserId,
                'new_email' => $newEmail
            ]);
            throw new \InvalidArgumentException('Supabase User ID and email cannot be empty');
        }

        $url = "{$this->baseUrl}/auth/v1/admin/users/{$supabaseUserId}";

        Log::info("🔧 Supabase updateUserEmail - START", [
            'supabase_user_id' => $supabaseUserId,
            'new_email' => $newEmail,
            'url' => $url
        ]);

        $payload = [
            'email' => $newEmail,
            'email_confirm' => true,
        ];

        try {
            Log::info("🔧 Supabase updateUserEmail - Making HTTP Request", [
                'payload' => $payload,
                'service_key_length' => strlen($this->serviceKey)
            ]);

            $response = Http::timeout(30)
                ->withHeaders([
                    'apikey'        => $this->serviceKey,
                    'Authorization' => 'Bearer ' . $this->serviceKey,
                    'Content-Type'  => 'application/json',
                ])
                ->put($url, $payload);

            Log::info("🔧 Supabase updateUserEmail - HTTP Response Received", [
                'status_code' => $response->status(),
                'successful' => $response->successful(),
                'body_preview' => substr($response->body(), 0, 500) // First 500 chars
            ]);

            // Detailed logging based on status
            if ($response->successful()) {
                Log::info("✅ Supabase updateUserEmail - SUCCESS", [
                    'supabase_user_id' => $supabaseUserId,
                    'new_email' => $newEmail,
                    'response_body' => $response->body()
                ]);
            } else {
                Log::error("❌ Supabase updateUserEmail - FAILED", [
                    'url' => $url,
                    'status_code' => $response->status(),
                    'response_body' => $response->body(),
                    'payload' => $payload,
                    'headers_sent' => [
                        'apikey' => '***' . substr($this->serviceKey, -4),
                        'authorization' => 'Bearer ***' . substr($this->serviceKey, -4)
                    ]
                ]);

                throw new \RuntimeException(
                    "Supabase email update failed. Status: {$response->status()} - Response: {$response->body()}"
                );
            }
        } catch (\Exception $e) {
            Log::error("💥 Supabase updateUserEmail - EXCEPTION", [
                'exception_message' => $e->getMessage(),
                'exception_class' => get_class($e),
                'supabase_user_id' => $supabaseUserId,
                'url' => $url
            ]);
            throw $e;
        }
    }
    // Temporary fix - direct API call testing
    public function testSupabaseConnection()
    {
        $url = "{$this->baseUrl}/auth/v1/admin/users";

        $response = Http::withHeaders([
            'apikey'        => $this->serviceKey,
            'Authorization' => 'Bearer ' . $this->serviceKey,
        ])->get($url);

        Log::info("Supabase connection test", [
            'status' => $response->status(),
            'body' => $response->body(),
            'users_count' => $response->successful() ? count(json_decode($response->body(), true)) : 0
        ]);

        // Additional: Test specific user fetch
        if ($response->successful()) {
            $users = json_decode($response->body(), true);
            if (count($users) > 0) {
                $firstUser = $users[0];
                Log::info("First Supabase user sample", [
                    'id' => $firstUser['id'] ?? 'N/A',
                    'email' => $firstUser['email'] ?? 'N/A',
                    'created_at' => $firstUser['created_at'] ?? 'N/A'
                ]);
            }

            // Test the specific user ID from your logs
            $specificUserId = '5512e034-bf2e-4bfd-974b-13f6a772931a';
            $this->testSpecificUser($specificUserId);
        }

        return $response->successful();
    }
    public function testSpecificUser(string $userId)
    {
        $url = "{$this->baseUrl}/auth/v1/admin/users/{$userId}";

        $response = Http::withHeaders([
            'apikey'        => $this->serviceKey,
            'Authorization' => 'Bearer ' . $this->serviceKey,
        ])->get($url);

        Log::info("Supabase specific user test", [
            'user_id' => $userId,
            'status' => $response->status(),
            'body' => $response->body(),
            'success' => $response->successful()
        ]);

        return $response->successful();
    }
    // Add to SupabaseUserService class
public function findUserByEmail(string $email): ?array
{
    try {
        $url = "{$this->baseUrl}/auth/v1/admin/users";
        
        Log::info("Supabase findUserByEmail", ['email' => $email]);
        
        $response = Http::withHeaders([
            'apikey'        => $this->serviceKey,
            'Authorization' => 'Bearer ' . $this->serviceKey,
        ])->get($url);
        
        if ($response->successful()) {
            $users = $response->json();
            
            // Find user by email
            foreach ($users as $user) {
                if (($user['email'] ?? '') === $email) {
                    Log::info("Found existing Supabase user by email", [
                        'email' => $email,
                        'supabase_uid' => $user['id'] ?? null
                    ]);
                    return $user;
                }
            }
            
            Log::info("No existing Supabase user found for email", ['email' => $email]);
            return null;
        }
        
        Log::error("Failed to fetch users from Supabase", [
            'status' => $response->status(),
            'body' => $response->body()
        ]);
        return null;
        
    } catch (\Exception $e) {
        Log::error("Exception in findUserByEmail", [
            'email' => $email,
            'error' => $e->getMessage()
        ]);
        return null;
    }
}
// public function getUserByEmail(string $email): ?array
// {
//     try {
//         // Note: Supabase admin API doesn't have direct email lookup
//         // We need to work around this
        
//         $url = "{$this->baseUrl}/auth/v1/admin/users";
        
//         $response = Http::withHeaders([
//             'apikey'        => $this->serviceKey,
//             'Authorization' => 'Bearer ' . $this->serviceKey,
//         ])->get($url);
        
//         if ($response->successful()) {999
//             $users = $response->json();
            
//             // Find user by email (this is inefficient but works for small batches)
//             foreach ($users as $user) {
//                 if (($user['email'] ?? '') === $email) {
//                     return $user;
//                 }
//             }
//         }
        
//         return null;
        
//     } catch (\Exception $e) {
//         Log::error("Error getting user by email from Supabase", [
//             'email' => $email,
//             'error' => $e->getMessage()
//         ]);
//         return null;
//     }
// }
}
