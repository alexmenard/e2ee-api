<?php

namespace App\Controllers;

use App\Support\Response;
use App\Database\Database;
use PDO;

class UsersController
{
    public function devices(): Response
    {
        $userUuid = isset($_GET['user_uuid']) ? trim((string)$_GET['user_uuid']) : '';
        if ($userUuid === '') {
            return Response::error('user_uuid is required', 400);
        }

        $pdo = Database::connection();

        $stmt = $pdo->prepare("
            SELECT d.device_id
            FROM users u
            INNER JOIN devices d ON d.user_id = u.id
            WHERE u.uuid = :uuid
            ORDER BY d.created_at ASC
        ");
        $stmt->execute(['uuid' => $userUuid]);

        $devices = array_map(function ($r) { return $r['device_id']; }, $stmt->fetchAll(PDO::FETCH_ASSOC));

        return Response::json([
            'user_uuid' => $userUuid,
            'devices' => $devices
        ]);
    }
}
