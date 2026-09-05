<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;

class EncryptionService
{
    public function encryptBvn(?string $bvn): ?string
    {
        if (!$bvn)
            return null;
        return Crypt::encryptString($bvn);
    }

    public function decryptBvn(?string $encrypted): ?string
    {
        if (!$encrypted)
            return null;
        try {
            return Crypt::decryptString($encrypted);
        } catch (\Exception) {
            return null;
        }
    }
}