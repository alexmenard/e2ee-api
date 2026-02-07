<?php

namespace App\Middleware;

use App\Support\Request;
use App\Support\Response;
use App\Database\Database;
use PDO;

class AuthMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        $authHeader = $request->header('authorization', '');

        if (!$authHeader || stripos($authHeader, 'Bearer ') !== 0) {
            return Response::error('Unauthorized', 401);
        }

        $token = trim(substr($authHeader, 7));
        if ($token === '') {
            return Response::error('Unauthorized', 401);
        }

        $pdo = Database::connection();

        $stmt = $pdo->prepare("
            SELECT user_id, device_id
            FROM sessions
            WHERE token = :token
              AND expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute(['token' => $token]);

        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$session) {
            return Response::error('Invalid or expired token', 401);
        }

        $request->setAttribute('user_id', (int)$session['user_id']);
        $request->setAttribute('device_id', $session['device_id']);

        return $next($request);
    }
}
