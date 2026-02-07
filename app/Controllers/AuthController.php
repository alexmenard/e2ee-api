<?php

namespace App\Controllers;

use App\Support\Request;
use App\Support\Response;
use App\Services\AuthService;

class AuthController
{
    public function register(Request $request): Response
    {
        $body = $request->getBody();

        $email = $body['email'] ?? '';
        $pass  = $body['password'] ?? '';

        $service = new AuthService();
        $result = $service->register($email, $pass);

        if (isset($result['error'])) {
            if ($result['error'] === 'invalid_email')  return Response::error('Invalid email', 400);
            if ($result['error'] === 'weak_password')  return Response::error('Password must be at least 8 characters', 400);
            if ($result['error'] === 'email_taken')    return Response::error('Email already in use', 409);
            return Response::error('Registration failed', 400);
        }

        // For public API, you can return only user_uuid
        return Response::json([
            'user_uuid' => $result['user_uuid']
        ], 201);
    }

    public function login(Request $request): Response
    {
        $body = $request->getBody();

        $email    = $body['email'] ?? '';
        $password = $body['password'] ?? '';
        $deviceId = $body['device_id'] ?? null; // optional but recommended

        $service = new AuthService();
        $result = $service->login($email, $password, $deviceId);

        if (isset($result['error'])) {
            return Response::error('Invalid credentials', 401);
        }

        return Response::json($result);
    }
}
