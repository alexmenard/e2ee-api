<?php

namespace App\Controllers;

use App\Support\Request;
use App\Support\Response;
use App\Services\MessagesService;

class MessagesController
{
    // POST /messages/send  (protected)
    public function send(Request $request): Response
    {
        $userId = (int)$request->getAttribute('user_id');
        $senderDeviceId = (string)$request->getAttribute('device_id'); // from session token

        if ($userId <= 0 || $senderDeviceId === '') {
            return Response::error('Unauthorized', 401);
        }

        $body = $request->getBody();

        $recipientDeviceId = $body['recipient_device_id'] ?? '';
        $ciphertext = $body['ciphertext'] ?? '';

        if (!$recipientDeviceId || !$ciphertext) {
            return Response::error('recipient_device_id and ciphertext are required', 400);
        }

        $service = new MessagesService();
        $result = $service->send($userId, $senderDeviceId, (string)$recipientDeviceId, (string)$ciphertext);

        if (isset($result['error'])) {
            switch ($result['error']) {
                case 'unknown_recipient_device':
                    return Response::error('Unknown recipient device', 404);
                case 'sender_device_not_allowed':
                    return Response::error('Sender device not allowed', 403);
                case 'missing_ciphertext':
                    return Response::error('Missing ciphertext', 400);
                default:
                    return Response::error('Message send failed', 400);
            }
        }

        return Response::json($result, 201);
    }

    // GET /messages/inbox?after_id=0&limit=50  (protected)
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

    public function with(Request $request): Response
    {
        $myDeviceId = (string)$request->getAttribute('device_id');
        if ($myDeviceId === '') {
            return Response::error('Unauthorized', 401);
        }

        $otherDeviceId = isset($_GET['device_id']) ? (string)$_GET['device_id'] : '';
        $afterId = isset($_GET['after_id']) ? (int)$_GET['after_id'] : 0;
        $limit   = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;

        if (trim($otherDeviceId) === '') {
            return Response::error('device_id query param is required', 400);
        }

        $service = new MessagesService();
        $result = $service->conversation($myDeviceId, $otherDeviceId, $afterId, $limit);

        if (isset($result['error'])) {
            switch ($result['error']) {
                case 'unknown_other_device':
                    return Response::error('Unknown device_id', 404);
                case 'invalid_device_id':
                    return Response::error('Invalid device_id', 400);
                default:
                    return Response::error('Conversation fetch failed', 500);
            }
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
