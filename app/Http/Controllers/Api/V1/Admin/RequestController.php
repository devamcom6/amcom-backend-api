<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\RequestKind;
use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminRequestResource;
use App\Models\ServiceRequest;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Contract §15.7 — requests.view / requests.manage */
class RequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $list = ListQuery::from($request);

        $query = ServiceRequest::query()
            ->with('assignee')
            ->when($list->filter('kind', RequestKind::values()), fn ($q, $v) => $q->whereIn('kind', $v))
            ->when($list->filter('status', RequestStatus::values()), fn ($q, $v) => $q->whereIn('status', $v))
            ->when($list->search(), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('reference', 'like', "%{$term}%")
                ->orWhere('name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('subject_line', 'like', "%{$term}%")));

        $list->applySort($query, [
            'created_at' => 'created_at',
            'scheduled_for' => 'scheduled_for',
        ], '-created_at');

        return ApiResponse::paginated($list->paginate($query, 25), AdminRequestResource::class);
    }

    public function update(Request $request, int $id, AuditLogger $audit): JsonResponse
    {
        $serviceRequest = ServiceRequest::query()->findOrFail($id);
        $validated = $request->validate([
            'status' => ['sometimes', Rule::enum(RequestStatus::class)],
            'assigned_to_me' => ['sometimes', 'boolean'],
        ]);

        $old = ['status' => $serviceRequest->status->value, 'assigned_to' => $serviceRequest->assigned_to];

        if (array_key_exists('status', $validated)) {
            $serviceRequest->status = RequestStatus::from($validated['status']);
        }

        if (array_key_exists('assigned_to_me', $validated)) {
            $serviceRequest->assigned_to = $validated['assigned_to_me'] ? $request->user()->id : null;
        }

        $serviceRequest->save();

        $audit->log('request.updated', $serviceRequest, $old, [
            'status' => $serviceRequest->status->value,
            'assigned_to' => $serviceRequest->assigned_to,
        ], 'updated request');

        return ApiResponse::success(new AdminRequestResource($serviceRequest->load('assignee')), 'Request updated.');
    }
}
