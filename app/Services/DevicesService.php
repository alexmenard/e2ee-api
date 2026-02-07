<?php

namespace App\Services;

use App\Database\Database;
use PDO;

class DevicesService
{
    public function registerDevice(int $userId, string $deviceId, string $identityKeyBase64): array
    {
        $deviceId = trim($deviceId);
        if ($deviceId === '' || strlen($deviceId) > 64) {
            return ['error' => 'invalid_device_id'];
        }

        $pdo = Database::connection();

        // Ensure device_id isn't already used
        $stmt = $pdo->prepare("SELECT id FROM devices WHERE device_id = :device_id LIMIT 1");
        $stmt->execute(['device_id' => $deviceId]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            return ['error' => 'device_id_taken'];
        }

        $stmt = $pdo->prepare("
            INSERT INTO devices (user_id, device_id, identity_key)
            VALUES (:user_id, :device_id, :identity_key)
        ");
        $stmt->execute([
            'user_id'      => $userId,
            'device_id'    => $deviceId,
            'identity_key' => $identityKeyBase64
        ]);

        return [
            'device_id' => $deviceId,
            'status'    => 'registered'
        ];
    }
}
