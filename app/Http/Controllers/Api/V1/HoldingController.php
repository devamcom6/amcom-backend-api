<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\HoldingStatus;
use App\Enums\HoldingType;
use App\Enums\MaintenanceStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\HoldingDetailResource;
use App\Http\Resources\HoldingResource;
use App\Http\Resources\MaintenanceRequestResource;
use App\Models\PropertyHolding;
use App\Notifications\OpsAlert;
use App\Services\Notifier;
use App\Services\SettingsService;
use App\Support\ApiResponse;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

/** Contract §10 — purchases, leases & rentals. */
class HoldingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $list = ListQuery::from($request);

        $query = PropertyHolding::query()
            ->where('user_id', $request->user()->id)
            ->with('property.photos')
            ->when($list->filter('holding_type', HoldingType::values()), fn ($q, $v) => $q->whereIn('holding_type', $v))
            ->when($list->filter('status', HoldingStatus::values()), fn ($q, $v) => $q->whereIn('status', $v))
            ->latest('id');

        return ApiResponse::paginated($list->paginate($query, 12), HoldingResource::class);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success(new HoldingDetailResource($this->find($request, $id)));
    }

    public function maintenance(Request $request, int $id, Notifier $notifier, SettingsService $settings): JsonResponse
    {
        $holding = $this->find($request, $id);
        $validated = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:150'],
            'description' => ['nullable', 'string', 'max:3000'],
        ]);

        $maintenance = $holding->maintenanceRequests()->create($validated + [
            'user_id' => $request->user()->id,
            'status' => MaintenanceStatus::Pending,
        ]);

        $notifier->user($request->user(), 'maintenance_logged', ['title' => $maintenance->title, 'property_title' => $holding->property?->title]);
        $notifier->ops('maintenance', 'Maintenance request: '.$maintenance->title, [
            'property' => $holding->property?->title,
            'customer' => $request->user()->email,
            'details' => $maintenance->description,
        ]);

        if ($facility = $settings->get('notifications.facility_manager_email')) {
            Notification::route('mail', $facility)->notify(new OpsAlert('maintenance', 'Maintenance request: '.$maintenance->title, [
                'property' => $holding->property?->title,
                'address' => $holding->property?->address,
                'customer' => $request->user()->displayName().' '.$request->user()->phone,
                'details' => $maintenance->description,
            ]));
        }

        return ApiResponse::created(new MaintenanceRequestResource($maintenance), 'Maintenance request logged.');
    }

    private function find(Request $request, int $id): PropertyHolding
    {
        return PropertyHolding::query()
            ->where('user_id', $request->user()->id)
            ->with(['property.photos', 'documents', 'payments', 'maintenanceRequests'])
            ->findOrFail($id);
    }
}
