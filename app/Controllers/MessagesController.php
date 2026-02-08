<?php

namespace App\Controllers;

use App\Support\Request;
use App\Support\Response;
use App\Services\MessagesService;

class MessagesController
{
    public function sendToUser(Request $request): Response
    {
        $userId = (int)$request->getAttribute('user_id');
        $senderDeviceId = (string)$request->getAttribute('device_id');

        if ($userId <= 0 || $senderDeviceId === '') {
            return Response::error('Unauthorized', 401);
        }

        $body = $request->getBody();
        $recipientUserUuid = $body['recipient_user_uuid'] ?? '';
        $payloads = $body['payloads'] ?? null;

        if (!is_array($payloads)) {
            return Response::error('payloads must be an array', 400);
        }

        $service = new \App\Services\MessagesService();
        $result = $service->sendToUser($userId, $senderDeviceId, (string)$recipientUserUuid, $payloads);

        if (isset($result['error'])) {
            switch ($result['error']) {
                case 'unknown_recipient_user':
                    return Response::error('Unknown recipient user', 404);
                case 'sender_device_not_allowed':
                    return Response::error('Sender device not allowed', 403);
                default:
                    return Response::error('Send-to-user failed', 500);
            }
        }

        return Response::json($result, 201);
    }

    public function inbox(Request $request): Response
    {
        $deviceId = (string)$request->getAttribute('device_id');
        if ($deviceId === '') {
            return Response::error('Unauthorized', 401);
        }

        // For now, we read query params via $_GET (we can add query parsing to Request later)
        $afterId = isset($_GET['after_id']) ? (int)$_GET['after_id'] : 0;
        $limit   = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;

        $service = new MessagesService();
        $result = $service->inbox($deviceId, $afterId, $limit);

        if (isset($result['error'])) {
            return Response::error('Inbox fetch failed', 500);
        }

        return Response::json($result);
    }

    public function ack(Request $request): Response
    {
        $deviceId = (string)$request->getAttribute('device_id');
        if ($deviceId === '') {
            return Response::error('Unauthorized', 401);
        }

        $body = $request->getBody();

        $type = $body['type'] ?? '';
        $ids  = $body['ids'] ?? null;

        if (!is_array($ids)) {
            return Response::error('ids must be an array of message ids', 400);
        }

        $service = new MessagesService();
        $result = $service->ack($deviceId, $ids, (string)$type);

        if (isset($result['error'])) {
            switch ($result['error']) {
                case 'invalid_type':
                    return Response::error('type must be delivered or read', 400);
                case 'no_ids':
                    return Response::error('No valid ids provided', 400);
                default:
                    return Response::error('Ack failed', 400);
            }
        }

        return Response::json($result);
    }
}
