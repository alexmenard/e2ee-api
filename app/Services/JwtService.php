<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JwtService
{
    private string $secret;
    private string $algo;

    public function __construct()
    {
        $config = require __DIR__ . '/../Config/jwt.php';
        $this->secret = $config['secret'];
        $this->algo   = $config['algo'];
    }

    public function decode(string $token): object
    {
        return JWT::decode($token, new Key($this->secret, $this->algo));
    }

    public function create(array $payload): string
    {
        return JWT::encode($payload, $this->secret, $this->algo);
    }
}
