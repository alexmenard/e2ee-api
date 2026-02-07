<?php

namespace App\Services;

use App\Database\Database;
use App\Support\Uuid;
use PDO;

class AuthService
{
    public function register(string $email, string $password): array
    {
        $email = strtolower(trim($email));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['error' => 'invalid_email'];
        }
        if (strlen($password) < 8) {
            return ['error' => 'weak_password'];
        }

        $pdo = Database::connection();

        // Check duplicate email
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $stmt->execute(['email' => $email]);
        if ($stmt->fetch()) {
            return ['error' => 'email_taken'];
        }

        $uuid = Uuid::v4();
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("
            INSERT INTO users (uuid, email, password_hash)
            VALUES (:uuid, :email, :hash)
        ");
        $stmt->execute([
            'uuid'  => $uuid,
            'email' => $email,
            'hash'  => $hash
        ]);

        $userId = (int)$pdo->lastInsertId();

        return [
            'user_uuid' => $uuid,
            'user_id'   => $userId // internal; you can omit returning this later if you prefer
        ];
    }

    public function login(string $email, string $password, ?string $deviceId = null): array
    {
        $email = strtolower(trim($email));
        $pdo = Database::connection();

        $stmt = $pdo->prepare("
            SELECT id, uuid, password_hash
            FROM users
            WHERE email = :email
            LIMIT 1
        ");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return ['error' => 'invalid_credentials'];
        }

        // device_id is optional at login (but recommended)
        $deviceId = $deviceId ? trim($deviceId) : 'unknown';

        $token = $this->issueSessionToken($pdo, (int)$user['id'], $deviceId, 3600);

        return [
            'token'     => $token,
            'user_uuid' => $user['uuid'],
            'device_id' => $deviceId
        ];
    }

    private function issueSessionToken(PDO $pdo, int $userId, string $deviceId, int $ttlSeconds): string
    {
        $token = bin2hex(random_bytes(32)); // 64 hex chars

        $stmt = $pdo->prepare("
            INSERT INTO sessions (token, user_id, device_id, expires_at)
            VALUES (:token, :user_id, :device_id, DATE_ADD(NOW(), INTERVAL :ttl SECOND))
        ");
        $stmt->execute([
            'token'     => $token,
            'user_id'   => $userId,
            'device_id' => $deviceId,
            'ttl'       => $ttlSeconds
        ]);

        return $token;
    }
}
