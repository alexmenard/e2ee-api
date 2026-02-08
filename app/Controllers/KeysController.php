<?php

namespace App\Controllers;

use App\Support\Request;
use App\Support\Response;
use App\Support\KeyValidator;
use App\Database\Database;
use PDO;

class KeysController
{
    // POST /keys/upload (protected)
    public function upload(Request $request): Response
    {
        $userId   = (int)$request->getAttribute('user_id');
        $deviceId = (string)$request->getAttribute('device_id'); // from session token

        if ($userId <= 0 || $deviceId === '') {
            return Response::error('Unauthorized', 401);
        }

        $body = $request->getBody();

        // signed_prekey: { key_id, public_key, signature }
        $spk = $body['signed_prekey'] ?? null;
        $otk = $body['one_time_prekeys'] ?? null; // array of { key_id, public_key }

        if (!is_array($spk) || !isset($spk['key_id'], $spk['public_key'], $spk['signature'])) {
            return Response::error('signed_prekey is required', 400);
        }
        if (!is_array($otk) || count($otk) < 1) {
            return Response::error('one_time_prekeys must be a non-empty array', 400);
        }

        // Validate signed prekey public key
        [$okPk, $spkPk] = KeyValidator::validateIdentityKeyBase64((string)$spk['public_key']);
        if (!$okPk) return Response::error('signed_prekey.public_key: ' . $spkPk, 400);

        // Validate signature (base64, expected length ~64 bytes)
        $sigB64 = trim((string)$spk['signature']);
        $sig = base64_decode($sigB64, true);
        if ($sig === false) return Response::error('signed_prekey.signature must be valid base64', 400);
        $sigLen = strlen($sig);
        if ($sigLen < 48 || $sigLen > 80) return Response::error('signed_prekey.signature length invalid', 400);
        $sigB64 = base64_encode($sig);

        $spkKeyId = (int)$spk['key_id'];
        if ($spkKeyId <= 0) return Response::error('signed_prekey.key_id must be > 0', 400);

        // Validate one-time prekeys
        $validatedOtk = [];
        foreach ($otk as $i => $row) {
            if (!is_array($row) || !isset($row['key_id'], $row['public_key'])) {
                return Response::error("one_time_prekeys[$i] invalid format", 400);
            }
            $kid = (int)$row['key_id'];
            if ($kid <= 0) return Response::error("one_time_prekeys[$i].key_id must be > 0", 400);

            [$ok, $pk] = KeyValidator::validateIdentityKeyBase64((string)$row['public_key']);
            if (!$ok) return Response::error("one_time_prekeys[$i].public_key: $pk", 400);

            $validatedOtk[] = ['key_id' => $kid, 'public_key' => $pk];
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            // Upsert signed prekey (replace if same key_id)
            $stmt = $pdo->prepare("
                INSERT INTO signed_prekeys (device_id, key_id, public_key, signature)
                VALUES (:device_id, :key_id, :public_key, :signature)
                ON DUPLICATE KEY UPDATE public_key = VALUES(public_key), signature = VALUES(signature)
            ");
            $stmt->execute([
                'device_id'   => $deviceId,
                'key_id'      => $spkKeyId,
                'public_key'  => $spkPk,
                'signature'   => $sigB64
            ]);

            // Insert one-time prekeys (ignore duplicates)
            $stmt = $pdo->prepare("
                INSERT IGNORE INTO one_time_prekeys (device_id, key_id, public_key)
                VALUES (:device_id, :key_id, :public_key)
            ");
            foreach ($validatedOtk as $row) {
                $stmt->execute([
                    'device_id'  => $deviceId,
                    'key_id'     => $row['key_id'],
                    'public_key' => $row['public_key']
                ]);
            }

            $pdo->commit();

            // Keep only latest 2 signed prekeys per device
            $pdo->prepare("
                    DELETE FROM signed_prekeys
                    WHERE device_id = :device_id
                    AND id NOT IN (
                        SELECT id FROM (
                        SELECT id
                        FROM signed_prekeys
                        WHERE device_id = :device_id2
                        ORDER BY created_at DESC
                        LIMIT 2
                        ) t
                    )
                    ")->execute([
                'device_id' => $deviceId,
                'device_id2' => $deviceId
            ]);

            return Response::json([
                'status' => 'ok',
                'device_id' => $deviceId,
                'signed_prekey_key_id' => $spkKeyId,
                'one_time_prekeys_received' => count($validatedOtk)
            ], 201);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return Response::error('Failed to upload keys', 500);
        }
    }

    // GET /keys/bundle?device_id=... (public)
    public function bundle(Request $request): Response
    {
        // Query params not yet implemented in Request; easiest for now:
        $deviceId = $_GET['device_id'] ?? '';
        $deviceId = trim($deviceId);

        if ($deviceId === '' || strlen($deviceId) > 64) {
            return Response::error('device_id is required', 400);
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            // Identity key from devices table
            $stmt = $pdo->prepare("SELECT identity_key FROM devices WHERE device_id = :device_id LIMIT 1");
            $stmt->execute(['device_id' => $deviceId]);
            $dev = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$dev) {
                $pdo->rollBack();
                return Response::error('Unknown device', 404);
            }

            // Latest signed prekey (by created_at)
            $stmt = $pdo->prepare("
                SELECT key_id, public_key, signature
                FROM signed_prekeys
                WHERE device_id = :device_id
                ORDER BY created_at DESC
                LIMIT 1
            ");
            $stmt->execute(['device_id' => $deviceId]);
            $spk = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$spk) {
                $pdo->rollBack();
                return Response::error('No signed prekey available', 404);
            }

            // Pick one unused one-time prekey and mark it used
            $stmt = $pdo->prepare("
                SELECT id, key_id, public_key
                FROM one_time_prekeys
                WHERE device_id = :device_id AND used_at IS NULL
                ORDER BY id ASC
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute(['device_id' => $deviceId]);
            $otk = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($otk) {
                $upd = $pdo->prepare("UPDATE one_time_prekeys SET used_at = NOW() WHERE id = :id");
                $upd->execute(['id' => (int)$otk['id']]);
            }

            $pdo->commit();

            return Response::json([
                'device_id' => $deviceId,
                'identity_key' => $dev['identity_key'],
                'signed_prekey' => [
                    'key_id' => (int)$spk['key_id'],
                    'public_key' => $spk['public_key'],
                    'signature' => $spk['signature'],
                ],
                'one_time_prekey' => $otk ? [
                    'key_id' => (int)$otk['key_id'],
                    'public_key' => $otk['public_key'],
                ] : null
            ]);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return Response::error('Failed to fetch bundle', 500);
        }
    }

    // GET /keys/status (protected)
    public function status(Request $request): Response
    {
        $deviceId = (string)$request->getAttribute('device_id');
        if ($deviceId === '') {
            return Response::error('Unauthorized', 401);
        }

        $MIN_UNUSED = 20;
        $MAX_UNUSED = 200;

        $pdo = \App\Database\Database::connection();

        // Current signed prekey (latest)
        $stmt = $pdo->prepare("
        SELECT key_id, created_at
        FROM signed_prekeys
        WHERE device_id = :device_id
        ORDER BY created_at DESC
        LIMIT 1
    ");
        $stmt->execute(['device_id' => $deviceId]);
        $spk = $stmt->fetch(\PDO::FETCH_ASSOC);

        // Count unused one-time prekeys
        $stmt = $pdo->prepare("
        SELECT COUNT(*) AS cnt
        FROM one_time_prekeys
        WHERE device_id = :device_id AND used_at IS NULL
    ");
        $stmt->execute(['device_id' => $deviceId]);
        $unused = (int)$stmt->fetch(\PDO::FETCH_ASSOC)['cnt'];

        return Response::json([
            'device_id' => $deviceId,
            'signed_prekey' => $spk ? [
                'key_id' => (int)$spk['key_id'],
                'created_at' => $spk['created_at']
            ] : null,
            'one_time_prekeys' => [
                'unused' => $unused,
                'min_required' => $MIN_UNUSED,
                'max_allowed' => $MAX_UNUSED,
                'needs_more' => ($unused < $MIN_UNUSED),
                'recommended_upload' => max(0, $MAX_UNUSED - $unused)
            ]
        ]);
    }
}
