<?php

namespace App\Http\Resources;

use App\Support\Format;
use Illuminate\Http\Request;

/** PropertyHoldingDetail (contract §10): adds documents, payments and maintenance requests. */
class HoldingDetailResource extends HoldingResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'documents' => DocumentResource::collection($this->documents)->resolve($request),
            'payments' => $this->payments->map(fn ($p) => [
                'id' => $p->id,
                'date' => Format::ymd($p->date),
                'description' => $p->description,
                'amount' => $p->amount,
                'status' => $p->status,
            ])->values()->all(),
            'maintenance_requests' => MaintenanceRequestResource::collection($this->maintenanceRequests)->resolve($request),
        ];
    }
}
