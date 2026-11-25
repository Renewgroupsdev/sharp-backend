<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\Mobile\CommonFloorController as MobileCommonFloorController;

/** Same behaviour as mobile; moderation is open here because the route group is Super Admin only. */
class CommonFloorController extends MobileCommonFloorController {}
