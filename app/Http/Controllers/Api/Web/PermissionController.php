<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\ApiController;
use App\Services\AuditLogger;
use App\Services\PermissionTree;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Roles & Permissions: the module tree, who may use each node, and what the signed-in role may use. */
class PermissionController extends ApiController
{
    public function __construct(private readonly PermissionTree $tree, private readonly AuditLogger $audit) {}

    /** What the signed-in role may use (every signed-in person). */
    public function mine(Request $request): JsonResponse
    {
        return $this->ok($this->tree->forRole($request->user()->role->value));
    }

    /** The whole tree with each node's roles (Super Admin and Admin). */
    public function tree(): JsonResponse
    {
        return $this->ok(['roles' => PermissionTree::ROLES, 'modules' => $this->tree->tree()]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'modules' => ['nullable', 'array'], 'modules.*' => ['array'],
            'actions' => ['nullable', 'array'], 'actions.*' => ['array'],
            'tabs' => ['nullable', 'array'], 'tabs.*' => ['array'],
        ]);
        $this->tree->save($data);
        $this->audit->record($request->user(), 'Updated roles and permissions', null, 'Assign permissions');

        return $this->ok(['modules' => $this->tree->tree()]);
    }

    public function reset(Request $request): JsonResponse
    {
        PermissionTree::seedDefaults();
        $this->audit->record($request->user(), 'Reset roles and permissions', null, 'Defaults restored');

        return $this->ok(['modules' => $this->tree->tree()]);
    }
}
