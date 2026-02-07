<?php

namespace App\Support;

class KeyValidator
{
    public static function validateIdentityKeyBase64(string $b64): array
    {
        $b64 = trim($b64);

        $decoded = base64_decode($b64, true);
        if ($decoded === false) {
            return [false, 'identity_key must be valid base64'];
        }

        $len = strlen($decoded);
        if ($len !== 32 && $len !== 33) {
            return [false, 'identity_key must decode to 32 or 33 bytes'];
        }

        $canonical = base64_encode($decoded);

        return [true, $canonical];
    }
}
