<?php

namespace App\Controllers;

use App\Support\Request;
use App\Support\Response;
use App\Support\KeyValidator;
use App\Services\DevicesService;

class DevicesController
{
    public function register(Request $request): Response
    {
        $userId = (int)$request->getAttribute('user_id');
        if ($userId <= 0) {
            return Response::error('Unauthorized', 401);
        }

        $body = $request->getBody();
        $deviceId = $body['device_id'] ?? '';
        $identityKey = $body['identity_key'] ?? '';

        if (!$deviceId || !$identityKey) {
            return Response::error('device_id and identity_key are required', 400);
        }

        [$ok, $value] = KeyValidator::validateIdentityKeyBase64($identityKey);
        if (!$ok) {
            return Response::error($value, 400);
        }

        $service = new DevicesService();
        $result = $service->registerDevice($userId, $deviceId, $value);

        if (isset($result['error'])) {
            if ($result['error'] === 'invalid_device_id') return Response::error('Invalid device_id', 400);
            if ($result['error'] === 'device_id_taken')   return Response::error('device_id already used', 409);
            return Response::error('Device registration failed', 400);
        }

        return Response::json($result, 201);
    }
}
