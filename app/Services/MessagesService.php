<?php

namespace App\Services;

use App\Database\Database;
use PDO;

class MessagesService
{
    public function send(int $userId, string $senderDeviceId, string $recipientDeviceId, string $ciphertext): array
    {
        $senderDeviceId = trim($senderDeviceId);
        $recipientDeviceId = trim($recipientDeviceId);

        if ($senderDeviceId === '' || $recipientDeviceId === '' || strlen($recipientDeviceId) > 64) {
            return ['error' => 'invalid_device_id'];
        }

        if ($senderDeviceId !== $recipientDeviceId && $senderDeviceId === '') {
            return ['error' => 'invalid_sender'];
        }

        if ($ciphertext === '') {
            return ['error' => 'missing_ciphertext'];
        }

        $pdo = Database::connection();

        // 1) Make sure sender device belongs to this authenticated user
        $stmt = $pdo->prepare("
            SELECT user_id
            FROM devices
            WHERE device_id = :device_id
            LIMIT 1
        ");
        $stmt->execute(['device_id' => $senderDeviceId]);
        $senderRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$senderRow || (int)$senderRow['user_id'] !== $userId) {
            return ['error' => 'sender_device_not_allowed'];
        }

        // 2) Make sure recipient device exists
        $stmt = $pdo->prepare("
            SELECT id
            FROM devices
            WHERE device_id = :device_id
            LIMIT 1
        ");
        $stmt->execute(['device_id' => $recipientDeviceId]);
        $recipientRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$recipientRow) {
            return ['error' => 'unknown_recipient_device'];
        }

        // 3) Store ciphertext (server never decrypts)
        $stmt = $pdo->prepare("
            INSERT INTO messages (sender_device_id, recipient_device_id, ciphertext)
            VALUES (:sender, :recipient, :ciphertext)
        ");
        $stmt->execute([
            'sender'    => $senderDeviceId,
            'recipient' => $recipientDeviceId,
            'ciphertext' => $ciphertext
        ]);

        return [
            'message_id' => (int)$pdo->lastInsertId(),
            'status'     => 'queued'
        ];
    }

    public function inbox(string $deviceId, int $afterId, int $limit): array
    {
        $deviceId = trim($deviceId);

        if ($deviceId === '') {
            return ['error' => 'invalid_device_id'];
        }

        if ($limit < 1) $limit = 50;
        if ($limit > 200) $limit = 200;

        if ($afterId < 0) $afterId = 0;

        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            // Fetch messages for this device
            $stmt = $pdo->prepare("
                SELECT id, sender_device_id, recipient_device_id, ciphertext, created_at
                FROM messages
                WHERE recipient_device_id = :device_id
                  AND id > :after_id
                ORDER BY id ASC
                LIMIT :lim
            ");
            $stmt->bindValue(':device_id', $deviceId, PDO::PARAM_STR);
            $stmt->bindValue(':after_id', $afterId, PDO::PARAM_INT);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Mark delivered_at for fetched messages (optional but useful)
            if (!empty($rows)) {
                $ids = array_map(function ($r) {
                    return (int)$r['id'];
                }, $rows);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));

                $upd = $pdo->prepare("
                    UPDATE messages
                    SET delivered_at = COALESCE(delivered_at, NOW())
                    WHERE id IN ($placeholders)
                ");
                $upd->execute($ids);
            }

            $pdo->commit();

            return [
                'device_id' => $deviceId,
                'after_id'  => $afterId,
                'count'     => count($rows),
                'messages'  => array_map(function ($r) {
                    return [
                        'id' => (int)$r['id'],
                        'from_device_id' => $r['sender_device_id'],
                        'ciphertext' => $r['ciphertext'],
                        'created_at' => $r['created_at']
                    ];
                }, $rows)
            ];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return ['error' => 'inbox_failed'];
        }
    }

    public function ack(string $deviceId, array $ids, string $type): array
    {
        $deviceId = trim($deviceId);
        if ($deviceId === '') return ['error' => 'invalid_device'];

        $type = strtolower(trim($type));
        if ($type !== 'delivered' && $type !== 'read') {
            return ['error' => 'invalid_type'];
        }

        // Sanitize ids
        $clean = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0) $clean[] = $id;
        }
        $clean = array_values(array_unique($clean));

        if (count($clean) === 0) {
            return ['error' => 'no_ids'];
        }

        $pdo = Database::connection();

        // Build placeholders
        $placeholders = implode(',', array_fill(0, count($clean), '?'));

        if ($type === 'delivered') {
            $sql = "
            UPDATE messages
            SET delivered_at = COALESCE(delivered_at, NOW())
            WHERE recipient_device_id = ?
              AND id IN ($placeholders)
        ";
        } else { // read
            $sql = "
            UPDATE messages
            SET
              delivered_at = COALESCE(delivered_at, NOW()),
              read_at = COALESCE(read_at, NOW())
            WHERE recipient_device_id = ?
              AND id IN ($placeholders)
        ";
        }

        $params = array_merge([$deviceId], $clean);

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return [
            'type' => $type,
            'device_id' => $deviceId,
            'requested' => count($clean),
            'updated' => $stmt->rowCount()
        ];
    }

    public function conversation(string $myDeviceId, string $otherDeviceId, int $afterId, int $limit): array
    {
        $myDeviceId = trim($myDeviceId);
        $otherDeviceId = trim($otherDeviceId);
    
        if ($myDeviceId === '' || $otherDeviceId === '' || strlen($otherDeviceId) > 64) {
            return ['error' => 'invalid_device_id'];
        }
    
        if ($limit < 1) $limit = 50;
        if ($limit > 200) $limit = 200;
        if ($afterId < 0) $afterId = 0;
    
        $pdo = Database::connection();
        $pdo->beginTransaction();
    
        try {
            // Optional: ensure other device exists
            $chk = $pdo->prepare("SELECT 1 FROM devices WHERE device_id = :d LIMIT 1");
            $chk->execute(['d' => $otherDeviceId]);
            if (!$chk->fetch(PDO::FETCH_ASSOC)) {
                $pdo->rollBack();
                return ['error' => 'unknown_other_device'];
            }
    
            $limit = (int)$limit;

            $sql = "
                SELECT id, sender_device_id, recipient_device_id, ciphertext, created_at
                FROM messages
                WHERE id > :after_id
                  AND (
                    (sender_device_id = :me1 AND recipient_device_id = :other1)
                    OR
                    (sender_device_id = :other2 AND recipient_device_id = :me2)
                  )
                ORDER BY id ASC
                LIMIT $limit
            ";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'after_id' => (int)$afterId,
                'me1'      => $myDeviceId,
                'me2'      => $myDeviceId,
                'other1'   => $otherDeviceId,
                'other2'   => $otherDeviceId,
            ]);
            
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Mark delivered only for messages received by me
            if (!empty($rows)) {
                $idsToDeliver = [];
                foreach ($rows as $r) {
                    if ($r['recipient_device_id'] === $myDeviceId) {
                        $idsToDeliver[] = (int)$r['id'];
                    }
                }
    
                if (!empty($idsToDeliver)) {
                    $placeholders = implode(',', array_fill(0, count($idsToDeliver), '?'));
                    $upd = $pdo->prepare("
                        UPDATE messages
                        SET delivered_at = COALESCE(delivered_at, NOW())
                        WHERE recipient_device_id = ?
                          AND id IN ($placeholders)
                    ");
                    $upd->execute(array_merge([$myDeviceId], $idsToDeliver));
                }
            }
    
            $pdo->commit();
    
            $messages = array_map(function ($r) use ($myDeviceId) {
                $from = $r['sender_device_id'];
                return [
                    'id' => (int)$r['id'],
                    'from_device_id' => $from,
                    'to_device_id'   => $r['recipient_device_id'],
                    'direction'      => ($from === $myDeviceId) ? 'out' : 'in',
                    'ciphertext'     => $r['ciphertext'],
                    'created_at'     => $r['created_at'],
                ];
            }, $rows);
    
            return [
                'me'       => $myDeviceId,
                'other'    => $otherDeviceId,
                'after_id' => (int)$afterId,
                'count'    => count($messages),
                'messages' => $messages
            ];
    
        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log("conversation_failed: " . $e->getMessage());
            error_log($e->getTraceAsString());
            return ['error' => 'conversation_failed'];
        }   
    }
}
