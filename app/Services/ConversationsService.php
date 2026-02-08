<?php

namespace App\Services;

use App\Database\Database;
use PDO;

class ConversationsService
{
    public function listConversations(int $myUserId, int $limit, ?int $cursor): array
    {
        if ($limit < 1) $limit = 50;
        if ($limit > 200) $limit = 200;

        $cursor = $cursor !== null ? (int)$cursor : null;
        if ($cursor !== null && $cursor < 1) $cursor = null;

        $pdo = \App\Database\Database::connection();

        // Filter conversations older than cursor using HAVING on MAX(m.id)
        $having = $cursor !== null ? "HAVING MAX(m.id) < :cursor" : "";

        $sql = "
        SELECT
            other_u.uuid AS other_user_uuid,
            MAX(m.id) AS last_message_id,
            MAX(m.created_at) AS last_message_at,
            SUBSTRING_INDEX(
                GROUP_CONCAT(m.sender_device_id ORDER BY m.id DESC),
                ',', 1
            ) AS last_from_device_id,
            SUM(
                CASE
                    WHEN m.read_at IS NULL AND rd.user_id = :me1 THEN 1
                    ELSE 0
                END
            ) AS unread_count
        FROM messages m
        INNER JOIN devices sd ON sd.device_id = m.sender_device_id
        INNER JOIN devices rd ON rd.device_id = m.recipient_device_id
        INNER JOIN users su ON su.id = sd.user_id
        INNER JOIN users ru ON ru.id = rd.user_id
        INNER JOIN users other_u ON other_u.id = (
            CASE
                WHEN su.id = :me2 THEN ru.id
                ELSE su.id
            END
        )
        WHERE su.id = :me3 OR ru.id = :me4
        GROUP BY other_u.uuid
        $having
        ORDER BY last_message_id DESC
        LIMIT $limit
        ";

        $params = [
            'me1' => $myUserId,
            'me2' => $myUserId,
            'me3' => $myUserId,
            'me4' => $myUserId,
        ];
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $conversations = array_map(function ($r) {
            return [
                'other_user_uuid'     => $r['other_user_uuid'],
                'unread_count'        => (int)$r['unread_count'],
                'last_message_id'     => (int)$r['last_message_id'],
                'last_message_at'     => $r['last_message_at'],
                'last_from_device_id' => $r['last_from_device_id'],
            ];
        }, $rows);

        // next_cursor = the last item’s last_message_id (since DESC order)
        $nextCursor = null;
        if (!empty($conversations)) {
            $last = end($conversations);
            $nextCursor = $last['last_message_id'];
        }

        return [
            'count'         => count($conversations),
            'cursor'        => $cursor,
            'next_cursor'   => $nextCursor,
            'conversations' => $conversations,
        ];
    }

    public function messagesWithUser(int $myUserId, string $otherUserUuid, int $afterId, int $limit): array
    {
        $otherUserUuid = trim($otherUserUuid);
        if ($otherUserUuid === '') return ['error' => 'missing_other_user'];

        if ($afterId < 0) $afterId = 0;
        if ($limit < 1) $limit = 50;
        if ($limit > 200) $limit = 200;

        $pdo = Database::connection();

        // Resolve other user id
        $stmt = $pdo->prepare("SELECT id FROM users WHERE uuid = :uuid LIMIT 1");
        $stmt->execute(['uuid' => $otherUserUuid]);
        $other = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$other) return ['error' => 'unknown_other_user'];
        $otherUserId = (int)$other['id'];

        $limit = (int)$limit;

        // Fetch messages where (sender in my devices AND recipient in their devices)
        // OR (sender in their devices AND recipient in my devices)
        $sql = "
            SELECT
                m.id,
                m.sender_device_id,
                m.recipient_device_id,
                m.ciphertext,
                m.created_at,
                CASE
                    WHEN sd.user_id = :me_user_id_1 THEN 'out' ELSE 'in'
                END AS direction
            FROM messages m
            INNER JOIN devices sd ON sd.device_id = m.sender_device_id
            INNER JOIN devices rd ON rd.device_id = m.recipient_device_id
            WHERE m.id > :after_id
              AND (
                    (sd.user_id = :me_user_id_2 AND rd.user_id = :other_user_id_1)
                 OR (sd.user_id = :other_user_id_2 AND rd.user_id = :me_user_id_3)
              )
            ORDER BY m.id ASC
            LIMIT $limit
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'after_id'        => (int)$afterId,
            'me_user_id_1'    => $myUserId,
            'me_user_id_2'    => $myUserId,
            'me_user_id_3'    => $myUserId,
            'other_user_id_1' => $otherUserId,
            'other_user_id_2' => $otherUserId,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // (Optional) mark delivered for messages where recipient belongs to me
        if (!empty($rows)) {
            $idsToDeliver = [];
            foreach ($rows as $r) {
                // delivered applies when the recipient device is one of my devices
                $idsToDeliver[] = (int)$r['id'];
            }

            $placeholders = implode(',', array_fill(0, count($idsToDeliver), '?'));
            $upd = $pdo->prepare("
                UPDATE messages m
                INNER JOIN devices rd ON rd.device_id = m.recipient_device_id
                SET m.delivered_at = COALESCE(m.delivered_at, NOW())
                WHERE rd.user_id = ?
                  AND m.id IN ($placeholders)
            ");
            $upd->execute(array_merge([$myUserId], $idsToDeliver));
        }

        return [
            'other_user_uuid' => $otherUserUuid,
            'after_id' => (int)$afterId,
            'count' => count($rows),
            'messages' => array_map(function ($r) {
                return [
                    'id' => (int)$r['id'],
                    'from_device_id' => $r['sender_device_id'],
                    'to_device_id'   => $r['recipient_device_id'],
                    'direction'      => $r['direction'],
                    'ciphertext'     => $r['ciphertext'],
                    'created_at'     => $r['created_at'],
                ];
            }, $rows)
        ];
    }

    public function markConversationRead(int $myUserId, string $otherUserUuid): array
    {
        $otherUserUuid = trim($otherUserUuid);
        if ($otherUserUuid === '') return ['error' => 'missing_other_user'];

        $pdo = Database::connection();

        // Resolve other user id
        $stmt = $pdo->prepare("SELECT id FROM users WHERE uuid = :uuid LIMIT 1");
        $stmt->execute(['uuid' => $otherUserUuid]);
        $other = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$other) return ['error' => 'unknown_other_user'];

        $otherUserId = (int)$other['id'];

        // Mark as read: only messages sent by other user to me, across devices
        $stmt = $pdo->prepare("
            UPDATE messages m
            INNER JOIN devices sd ON sd.device_id = m.sender_device_id
            INNER JOIN devices rd ON rd.device_id = m.recipient_device_id
            SET
            m.delivered_at = COALESCE(m.delivered_at, NOW()),
            m.read_at      = COALESCE(m.read_at, NOW())
            WHERE rd.user_id = :me_user_id
            AND sd.user_id = :other_user_id
            AND m.read_at IS NULL
        ");

        $stmt->execute([
            'me_user_id'    => $myUserId,
            'other_user_id' => $otherUserId
        ]);

        return [
            'other_user_uuid' => $otherUserUuid,
            'updated' => $stmt->rowCount()
        ];
    }
}
