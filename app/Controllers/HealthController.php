<?php

namespace App\Controllers;

use App\Support\Request;
use App\Support\Response;

class HealthController
{
    public function check(Request $request): Response
    {
        return Response::json([
            'status' => 'ok',
            'time'   => time()
        ]);
    }
}
