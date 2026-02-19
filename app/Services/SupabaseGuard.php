<?php

namespace App\Services;

use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Firebase\JWT\ExpiredException;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

class SupabaseGuard implements Guard
{
    protected Request $request;
    protected UserProvider $provider;
    protected ?Authenticatable $user = null;

    public function __construct(UserProvider $provider, Request $request)
    {
        $this->provider = $provider;
        $this->request = $request;
    }

    public function hasUser(): bool
    {
        return $this->user !== null;
    }

    public function setUser(Authenticatable $user): void
    {
        $this->user = $user;
    }

    public function user(): ?Authenticatable
    {
        if ($this->user) {
            return $this->user;
        }

        $token = $this->request->bearerToken();
        if (! $token)
            return null;

        try {
            $payload = JWT::decode($token, new Key(env('SUPABASE_JWT_SECRET'), 'HS256'));
        } catch (ExpiredException $e) {
            abort(401, 'Token expired');
        } catch (\Throwable $e) {
            abort(401, 'Invalid token');
        }

        if (empty($payload->email) || empty($payload->sub)) {
            abort(401, 'Invalid token');
        }

        $this->link($payload->email, $payload->sub);

        $user = $this->provider->retrieveByCredentials([
            'supabase_uid' => $payload->sub,
        ]);

        if (! $user) {
            abort(500, 'User Invalid');
        }

        return $this->user = $user;
    }

    public function check(): bool
    {
        return ! is_null($this->user());
    }
    public function guest(): bool
    {
        return ! $this->check();
    }
    public function id(): ?int
    {
        return $this->user()?->getAuthIdentifier();
    }
    public function validate(array $credentials = []): bool
    {
        return $this->check();
    }

    public function link(string $email, ?string $uid)
    {
        if (!$uid) return;

        $user = User::where('email', $email)->first();

        if ($user && $user->supabase_uid !== $uid) {
            $user->updateQuietly([
                'supabase_uid' => $uid
            ]);
        }
    }
}
