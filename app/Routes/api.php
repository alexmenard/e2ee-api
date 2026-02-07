<?php

use App\Controllers\HealthController;
use App\Controllers\AuthController;
use App\Controllers\DevicesController;
use App\Controllers\ProfileController;
use App\Middleware\AuthMiddleware;
use App\Controllers\KeysController;
use App\Controllers\MessagesController;

$router->get('/health', [HealthController::class, 'check']);

$router->post('/auth/register', [AuthController::class, 'register']);
$router->post('/auth/login',    [AuthController::class, 'login']);

$router->post('/devices/register', [DevicesController::class, 'register'], [
    AuthMiddleware::class
]);

$router->get('/me', [ProfileController::class, 'me'], [
    AuthMiddleware::class
]);

$router->post('/keys/upload', [KeysController::class, 'upload'], [
    AuthMiddleware::class
]);

$router->get('/keys/bundle', [KeysController::class, 'bundle']);

$router->post('/messages/send', [MessagesController::class, 'send'], [
    AuthMiddleware::class
]);

$router->get('/messages/inbox', [MessagesController::class, 'inbox'], [
    AuthMiddleware::class
]);

$router->get('/messages/with', [MessagesController::class, 'with'], [
    AuthMiddleware::class
]);

$router->post('/messages/ack', [MessagesController::class, 'ack'], [
    AuthMiddleware::class
]);
