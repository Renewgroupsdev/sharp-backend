<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\Mobile\DashboardController as MobileDashboardController;

/** Same behaviour as mobile; access is gated by the web route group (Super Admin). */
class DashboardController extends MobileDashboardController {}
