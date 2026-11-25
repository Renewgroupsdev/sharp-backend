<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\Mobile\AuditLogController as MobileAuditLogController;

/** Same behaviour as mobile; access is gated by the web route group (Super Admin). */
class AuditLogController extends MobileAuditLogController {}
