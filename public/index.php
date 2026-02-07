<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Support\Request;
use App\Router\Router;
use App\Support\Env;

Env::load(__DIR__ . '/../.env');

$request = new Request();
$router  = new Router($request);

// Load routes
require_once __DIR__ . '/../app/Routes/api.php';

// Dispatch request
$response = $router->dispatch();
$response->send();
