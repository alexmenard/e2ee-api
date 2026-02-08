<?php

namespace App\Controllers;

use App\Support\Request;
use App\Support\Response;
use App\Services\ConversationsService;

class ConversationsController
{
    public function index(Request $request): Response
    {
        $myUserId = (int)$request->getAttribute('user_id');
        if ($myUserId <= 0) return Response::error('Unauthorized', 401);
    
        $limit  = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $cursor = isset($_GET['cursor']) ? (int)$_GET['cursor'] : null;
    
        $svc = new ConversationsService();
        $data = $svc->listConversations($myUserId, $limit, $cursor);
    
        return Response::json($data);
    }

    public function withUser(Request $request): Response
    {
        $myUserId = (int)$request->getAttribute('user_id');
        if ($myUserId <= 0) return Response::error('Unauthorized', 401);

        $otherUserUuid = isset($_GET['user_uuid']) ? (string)$_GET['user_uuid'] : '';
        $afterId = isset($_GET['after_id']) ? (int)$_GET['after_id'] : 0;
        $limit   = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;

        $svc = new ConversationsService();
        $data = $svc->messagesWithUser($myUserId, $otherUserUuid, $afterId, $limit);

        if (isset($data['error'])) {
            if ($data['error'] === 'missing_other_user') return Response::error('user_uuid is required', 400);
            if ($data['error'] === 'unknown_other_user') return Response::error('Unknown user', 404);
            return Response::error('Conversation fetch failed', 500);
        }

        return Response::json($data);
    }

    public function markRead(Request $request): Response
    {
        $myUserId = (int)$request->getAttribute('user_id');
        if ($myUserId <= 0) return Response::error('Unauthorized', 401);

        $body = $request->getBody();
        $otherUserUuid = $body['user_uuid'] ?? '';

        $svc = new ConversationsService();
        $result = $svc->markConversationRead($myUserId, (string)$otherUserUuid);

        if (isset($result['error'])) {
            if ($result['error'] === 'missing_other_user') return Response::error('user_uuid is required', 400);
            if ($result['error'] === 'unknown_other_user') return Response::error('Unknown user', 404);
            return Response::error('Mark read failed', 500);
        }

        return Response::json($result);
    }
}
