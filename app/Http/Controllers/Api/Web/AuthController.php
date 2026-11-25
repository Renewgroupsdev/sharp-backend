<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\Mobile\AuthController as MobileAuthController;

/** Web sign-in: same flow as mobile, open to every role; only the token label differs. */
class AuthController extends MobileAuthController
{
    protected function device(): string
    {
        return 'web';
    }
}
