<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\CheckoutMode;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminEstateResource;
use App\Models\Estate;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Contract §15.3 — estates.view / estates.manage */
class EstateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $list = ListQuery::from($request);

        $query = Estate::query()
            ->withPlotStats()
            ->when($list->search(), fn ($q, $term) => $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('city', 'like', "%{$term}%")))
            ->when($list->bool('is_published') !== null, fn ($q) => $q->where('is_published', $list->bool('is_published')));

        $list->applySort($query, ['name' => 'name', 'created_at' => 'created_at'], 'name');

        return ApiResponse::paginated($list->paginate($query, 25), AdminEstateResource::class);
    }

    public function update(Request $request, int $id, AuditLogger $audit): JsonResponse
    {
        $estate = Estate::query()->findOrFail($id);
        $validated = $request->validate([
            'is_published' => ['sometimes', 'boolean'],
            'checkout_mode' => ['sometimes', Rule::enum(CheckoutMode::class)],
        ]);

        $old = ['is_published' => $estate->is_published, 'checkout_mode' => $estate->checkout_mode->value];
        $estate->fill($validated)->save();

        $audit->log('estate.updated', $estate, $old, $validated, $estate->is_published ? 'published estate' : 'unpublished estate');

        return ApiResponse::success(new AdminEstateResource(Estate::query()->withPlotStats()->findOrFail($estate->id)), 'Estate updated.');
    }
}
