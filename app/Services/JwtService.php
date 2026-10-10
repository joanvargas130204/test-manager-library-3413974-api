<?php

namespace App\Services;

use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Exception;

class JwtService
{
    private string $secret;
    private int $ttl;

    public function __construct()
    {
        $this->secret = env('JWT_SECRET', '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef');
        $this->ttl = 28800; // 8 horas en segundos
    }

    public function generateToken(User $user): array
    {
        $now = time();
        $payload = [
            'sub' => $user->id,
            'role' => $user->role,
            'iat' => $now,
            'exp' => $now + $this->ttl,
        ];

        $token = JWT::encode($payload, $this->secret, 'HS256');

        return [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $this->ttl,
            'role' => $user->role,
        ];
    }

    public function validateToken(string $token): ?object
    {
        try {
            return JWT::decode($token, new Key($this->secret, 'HS256'));
        } catch (Exception $e) {
            return null;
        }
    }
}
