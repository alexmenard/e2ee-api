<?php

namespace App\Controllers;

use App\Support\Request;
use App\Support\Response;

class ProfileController
{
    public function me(Request $request): Response
    {
        return Response::json([
            'user_id'   => $request->getAttribute('user_id'),
            'device_id' => $request->getAttribute('device_id'),
            'message'   => 'You are authenticated (DB token)'
        ]);
    }
}
